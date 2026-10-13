<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Exercise the actual startup script with an isolated volume and PHP process. */
final class EntrypointMigrationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $source = dirname(__DIR__, 2);
        $this->root = sys_get_temp_dir() . '/cimaise_container_' . uniqid();
        foreach (['docker', 'bin', 'database/migrations', 'image-migrations', 'storage/tmp', 'storage/cache/twig'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
        symlink($source . '/vendor', $this->root . '/vendor');
        copy($source . '/docker/migrate.php', $this->root . '/docker/migrate.php');
        copy($source . '/version.json', $this->root . '/version.json');
        foreach (glob($source . '/database/migrations/*.sql') ?: [] as $file) {
            copy($file, $this->root . '/image-migrations/' . basename($file));
        }
        // The persisted directory is deliberately unusable: startup must read
        // migrations baked outside this volume in the new image.
        file_put_contents($this->root . '/database/migrations/migrate_1.4.24_sqlite.sql', 'INVALID SQL;');
        $entrypoint = str_replace(['APP_DIR="/var/www/html"', '/usr/share/cimaise/migrations'],
            ['APP_DIR="' . $this->root . '"', $this->root . '/image-migrations'],
            (string) file_get_contents($source . '/docker/entrypoint.sh'));
        file_put_contents($this->root . '/entrypoint.sh', $entrypoint);
        // Native tests run as the current user. Only identity switching and
        // ownership changes are stubbed; shell control flow/PHP/SQL are real.
        file_put_contents($this->root . '/bin/chown', "#!/bin/sh\nexit 0\n");
        file_put_contents($this->root . '/bin/su', "#!/bin/sh\nshift 3\nexec /bin/sh -c \"\$1\"\n");
        chmod($this->root . '/bin/chown', 0755);
        chmod($this->root . '/bin/su', 0755);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    private function seedVolume(): PDO
    {
        file_put_contents($this->root . '/storage/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n");
        $db = new PDO('sqlite:' . $this->root . '/database/database.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = str_replace('  show_equipment INTEGER NOT NULL DEFAULT 1,' . "\n", '',
            (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $db->exec($schema);
        $db->exec("INSERT INTO categories (id,name,slug) VALUES (99999,'Review','review');
            INSERT INTO albums (id,title,slug,category_id,custom_cameras) VALUES (99999,'Review','review',99999,'Retained camera')");
        return $db;
    }

    /** @return array{int, string} */
    private function boot(): array
    {
        $env = getenv();
        foreach (array_keys($env) as $key) {
            if (str_starts_with($key, 'DB_')) {
                unset($env[$key]);
            }
        }
        $env['PATH'] = $this->root . '/bin:' . dirname(PHP_BINARY) . ':' . ($env['PATH'] ?? '');
        $process = proc_open(['/bin/sh', $this->root . '/entrypoint.sh', PHP_BINARY, '-r', 'echo "APACHE_STARTED\n";'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testRetainedVolumeIsMigratedBeforeApacheAndRestartIsIdempotent(): void
    {
        $db = $this->seedVolume();
        file_put_contents($this->root . '/storage/cache/twig/old.php', 'old compiled template');
        [$status, $output] = $this->boot();
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('APACHE_STARTED', $output);
        self::assertFileDoesNotExist($this->root . '/storage/cache/twig/old.php');
        self::assertSame(1, (int) $db->query('SELECT show_equipment FROM albums WHERE id = 99999')->fetchColumn());
        $db->exec('UPDATE albums SET show_equipment = 0 WHERE id = 99999');
        self::assertSame('Retained camera', $db->query('SELECT custom_cameras FROM albums WHERE id = 99999')->fetchColumn());
        [$status, $output] = $this->boot();
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('migrations applied: 0', $output);
        self::assertSame(0, (int) $db->query('SELECT show_equipment FROM albums WHERE id = 99999')->fetchColumn());
    }

    public function testMigrationFailureStopsApacheAndARepairedImageCanRetry(): void
    {
        $db = $this->seedVolume();
        $migration = $this->root . '/image-migrations/migrate_1.4.24_sqlite.sql';
        $sql = (string) file_get_contents($migration);
        file_put_contents($migration, $sql . "\nINVALID SQL;\n");
        [$status, $output] = $this->boot();
        self::assertSame(1, $status, $output);
        self::assertStringNotContainsString('APACHE_STARTED', $output);
        self::assertFalse($db->query("SELECT value FROM settings WHERE key = 'docker.schema_version'")->fetchColumn());
        file_put_contents($migration, $sql);
        [$status, $output] = $this->boot();
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('APACHE_STARTED', $output);
    }

    public function testFirstBootStillStartsTheInstallerWithoutCreatingADatabase(): void
    {
        [$status, $output] = $this->boot();
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('No .env yet', $output);
        self::assertStringContainsString('APACHE_STARTED', $output);
        self::assertFileDoesNotExist($this->root . '/database/database.sqlite');
    }
}
