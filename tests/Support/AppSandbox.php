<?php

declare(strict_types=1);

require_once __DIR__ . '/TestFilesystem.php';

/**
 * An isolated, runnable copy of the application under the system temp
 * directory, for tests that install or update a whole site.
 *
 * The copy has its own app/, public/, database/ and storage/, so nothing it
 * does can reach the developer's .env, database or uploads. vendor/ is not
 * copied: a small autoload shim loads the real dependencies and resolves the
 * App\ namespace from the copy, which keeps every dirname(__DIR__)-based path
 * inside the sandbox. All application code runs in child PHP processes.
 */
final class AppSandbox
{
    public readonly string $root;
    private readonly string $source;
    /** @var resource|null */
    private $server = null;
    private int $port = 0;
    private string $cookieJar;

    private function __construct()
    {
        $this->source = dirname(__DIR__, 2);
        $root = sys_get_temp_dir() . '/cimaise_sandbox_' . bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        $this->root = (string) realpath($root);
        $this->cookieJar = $this->root . '/.cookies';
    }

    /** A sandbox holding the current tree, not yet installed. */
    public static function create(): self
    {
        $sandbox = new self();
        foreach (['app', 'database', 'plugins', 'public', 'storage/translations'] as $dir) {
            $sandbox->copyTree($sandbox->source . '/' . $dir, $sandbox->root . '/' . $dir);
        }
        foreach (['version.json', 'composer.json', 'bin/console', 'storage/cache/lensfun.json', 'storage/.htaccess'] as $file) {
            if (is_file($sandbox->source . '/' . $file)) {
                @mkdir(dirname($sandbox->root . '/' . $file), 0775, true);
                copy($sandbox->source . '/' . $file, $sandbox->root . '/' . $file);
            }
        }
        foreach (['storage/cache', 'storage/logs', 'storage/tmp', 'storage/originals', 'storage/protected-media',
            'storage/rate_limits', 'storage/backups', 'public/media'] as $dir) {
            @mkdir($sandbox->root . '/' . $dir, 0775, true);
        }
        $sandbox->writeAutoloadShim($sandbox->root);
        return $sandbox;
    }

    /**
     * vendor/autoload.php for a tree at $root: the real dependencies, with
     * App\ classes taken from that tree.
     */
    public function writeAutoloadShim(string $root): void
    {
        @mkdir($root . '/vendor', 0775, true);
        $real = var_export($this->source . '/vendor/autoload.php', true);
        file_put_contents($root . '/vendor/autoload.php', <<<PHP
<?php
// Test shim: real dependencies, App\\ resolved from this tree.
\$loader = require {$real};
spl_autoload_register(static function (string \$class): void {
    if (str_starts_with(\$class, 'App\\\\')) {
        \$file = dirname(__DIR__) . '/app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
        if (is_file(\$file)) {
            require \$file;
        }
    }
}, true, true);
return \$loader;

PHP);
    }

    /**
     * Copy a directory tree. Uploaded media, local databases and private notes
     * are never part of a sandbox.
     */
    public function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'CLAUDE.md' || $entry === '.DS_Store') {
                continue;
            }
            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;
            if (is_link($source)) {
                continue;
            }
            if (is_dir($source)) {
                if ($source === $this->source . '/public/media') {
                    continue;
                }
                $this->copyTree($source, $target);
            } elseif (!str_ends_with($entry, '.sqlite') || $entry === 'template.sqlite') {
                copy($source, $target);
            }
        }
    }

    /**
     * Run PHP code inside the sandbox (cwd = root, sandbox autoloader loaded).
     *
     * @param array<string, string> $env Extra environment variables.
     * @return array{0: int, 1: string} Exit code and combined output.
     */
    public function php(string $code, array $env = []): array
    {
        $script = $this->root . '/.sandbox-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\nchdir(__DIR__);\nrequire __DIR__ . '/vendor/autoload.php';\n" . $code);
        try {
            // nosemgrep: php.lang.security.exec-use.exec-use -- fixed argv (no shell): the PHP binary running this test and a script this fixture wrote into its own temp directory.
            $process = proc_open(
                [PHP_BINARY, $script],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                $this->root,
                $this->environment($env)
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start PHP in the sandbox');
            }
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            return [proc_close($process), $output];
        } finally {
            TestFilesystem::remove($script);
        }
    }

    /**
     * Serve the sandbox with PHP's built-in server and return its base URL.
     *
     * @param array<string, string> $env Extra environment variables.
     */
    public function startServer(array $env = []): string
    {
        $this->stopServer();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a port');
        }
        $this->port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
        fclose($socket);

        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed argv (no shell): PHP's built-in server on a loopback port, serving this fixture's temp directory.
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->root . '/public', $this->root . '/public/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/storage/logs/server.out', 'a'], 2 => ['file', $this->root . '/storage/logs/server.out', 'a']],
            $pipes,
            $this->root . '/public',
            $this->environment($env)
        );
        if (!is_resource($this->server)) {
            throw new RuntimeException('Cannot start the sandbox server');
        }
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($probe !== false) {
                fclose($probe);
                return $this->baseUrl();
            }
            usleep(100000);
        }
        throw new RuntimeException('The sandbox server did not start');
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }
        $this->server = null;
    }

    /**
     * HTTP request against the sandbox server, with a cookie jar shared by
     * every call on this sandbox. Redirects are not followed.
     *
     * @param array<string, string>|null $form POST fields (null: GET).
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $path, ?array $form = null): array
    {
        $headers = [];
        $curl = curl_init($this->baseUrl() . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($form !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : ''];
    }

    /**
     * Install the site through the real web installer (public/installer.php),
     * posting the four wizard steps as the browser form does. The server must
     * be running.
     */
    public function installThroughWizard(string $email, string $password, string $siteTitle): void
    {
        $page = $this->request('/installer.php?step=database');
        if ($page['status'] !== 200 || !preg_match('/name="_csrf"[^>]*value="([a-f0-9]{64})"/', $page['body'], $match)) {
            throw new RuntimeException('Installer not reachable on a fresh tree (HTTP ' . $page['status'] . ')');
        }
        $steps = [
            'database' => ['db_type' => 'sqlite', 'db_database' => 'database.sqlite'],
            'admin' => [
                'admin_name' => 'Suite Tester', 'admin_email' => $email,
                'admin_password' => $password, 'admin_password_confirm' => $password, 'admin_language' => 'en',
            ],
            'settings' => [
                'site_title' => $siteTitle, 'site_description' => 'Installed by the test suite',
                'site_copyright' => '© Test', 'site_email' => $email, 'site_language' => 'en', 'admin_language' => 'en',
                'timezone' => 'UTC', 'date_format' => 'Y-m-d', 'home_template' => 'classic', 'gallery_template_id' => '4',
                'cache_enabled' => '1', 'compression_enabled' => '1',
            ],
            'install' => [],
        ];
        foreach ($steps as $step => $fields) {
            $response = $this->request('/installer.php?step=' . $step, ['_csrf' => $match[1]] + $fields);
            if ($response['status'] >= 400 || stripos($response['body'], 'fatal error') !== false) {
                throw new RuntimeException("Installer step '{$step}' failed (HTTP {$response['status']})");
            }
        }
        if (!is_file($this->root . '/storage/tmp/.installed')) {
            throw new RuntimeException('The installer did not complete');
        }
    }

    public function forgetCookies(): void
    {
        if (is_file($this->cookieJar)) {
            TestFilesystem::remove($this->cookieJar);
        }
    }

    public function database(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->root . '/database/database.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function destroy(): void
    {
        $this->stopServer();
        TestFilesystem::remove($this->root);
    }

    /**
     * The developer's own database and updater settings must not leak into
     * the sandbox through the environment.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function environment(array $extra): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            if (!preg_match('/^(DB_|APP_|UPDATER_|SESSION_|CIMAISE_)/', (string) $key)) {
                $env[(string) $key] = (string) $value;
            }
        }
        return $extra + $env;
    }
}
