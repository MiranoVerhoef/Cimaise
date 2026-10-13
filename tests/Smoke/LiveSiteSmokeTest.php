<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Read-only checks against a deployed site, for use right after an update:
 *
 *   CIMAISE_SMOKE_URL=https://example.org vendor/bin/phpunit -c phpunit.xml tests/Smoke
 *
 * Skipped unless CIMAISE_SMOKE_URL is set. Nothing is written to the site.
 */
final class LiveSiteSmokeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $base = rtrim((string) getenv('CIMAISE_SMOKE_URL'), '/');
        if ($base === '') {
            self::markTestSkipped('Set CIMAISE_SMOKE_URL to run the live smoke test');
        }
        if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $base)) {
            self::fail('CIMAISE_SMOKE_URL must be a plain http(s) URL');
        }
        $this->base = $base;
    }

    /**
     * @param string[] $headers
     * @return array{status: int, type: string, etag: string, body: string}
     */
    private function get(string $path, array $headers = []): array
    {
        $etag = '';
        $curl = curl_init(str_starts_with($path, 'http') ? $path : $this->base . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$etag): int {
                if (stripos($line, 'etag:') === 0) {
                    $etag = trim(substr($line, 5));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $result = [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
            'etag' => $etag,
            'body' => is_string($body) ? $body : '',
        ];
        curl_close($curl);
        return $result;
    }

    private static function assertCleanHtml(array $response, string $what): void
    {
        self::assertSame(200, $response['status'], $what);
        self::assertStringStartsWith('text/html', $response['type'], $what);
        // PHP notices leak into the markup when display_errors is on.
        self::assertSame(0, preg_match('/<b>(Fatal error|Warning|Deprecated|Notice)<\/b>|Twig\\\\Error/i', $response['body']), $what . ' contains a PHP/Twig error');
        self::assertStringContainsString('</html>', $response['body'], $what . ' is truncated');
    }

    public function testPublicPagesRender(): void
    {
        self::assertCleanHtml($this->get('/'), 'home');
        self::assertCleanHtml($this->get('/galleries'), 'galleries');
        $feed = $this->get('/feed.xml');
        self::assertSame(200, $feed['status']);
        self::assertStringContainsString('<rss', $feed['body']);
        $worker = $this->get('/sw.js');
        self::assertSame(200, $worker['status']);
        self::assertStringContainsString('javascript', $worker['type']);
    }

    public function testAdminIsGatedAndTheLoginPageRenders(): void
    {
        self::assertSame(302, $this->get('/admin')['status']);
        self::assertCleanHtml($this->get('/admin/login'), 'admin login');
    }

    public function testHomeRevalidatesWithItsEtag(): void
    {
        $home = $this->get('/');
        if ($home['etag'] === '') {
            self::markTestSkipped('the home page sends no ETag (page cache disabled?)');
        }
        self::assertSame(304, $this->get('/', ['If-None-Match: ' . $home['etag']])['status']);
    }

    public function testAlbumPagesRender(): void
    {
        // Album links live in different attributes depending on the template.
        preg_match_all('#["\'](?:https?://[^"\'/]+)?(/(?:[A-Za-z0-9._~-]+/)*album/[a-z0-9][a-z0-9-]*)["\']#', $this->get('/galleries')['body'], $matches);
        $paths = array_slice(array_values(array_unique($matches[1])), 0, 5);
        if ($paths === []) {
            self::markTestSkipped('no album listed on this site');
        }
        foreach ($paths as $path) {
            // Password and NSFW albums answer with their gate, which is a page too.
            self::assertCleanHtml($this->get($this->origin() . $path), 'album ' . $path);
        }
    }

    public function testImagesAreRealImageStreams(): void
    {
        // A PHP notice prepended to the bytes makes an image undecodable
        // while the status stays 200, so the content itself is checked.
        $html = $this->get('/')['body'] . $this->get('/galleries')['body'];
        preg_match_all('#["\' ,](?:https?://[^"\'/ ]+)?(/(?:[A-Za-z0-9._~-]+/)*media/[A-Za-z0-9._/-]+\.(?:jpe?g|webp|avif|png))#', $html, $matches);
        $paths = array_slice(array_values(array_unique($matches[1])), 0, 4);
        if ($paths === []) {
            self::markTestSkipped('no image referenced by the home or galleries page');
        }
        foreach ($paths as $path) {
            $media = $this->get($this->origin() . $path);
            self::assertSame(200, $media['status'], $path);
            self::assertStringStartsWith('image/', $media['type'], $path);
            $magic = substr($media['body'], 0, 12);
            self::assertTrue(
                str_starts_with($magic, "\xFF\xD8\xFF") || str_starts_with($magic, "\x89PNG")
                    || substr($magic, 8, 4) === 'WEBP' || substr($magic, 4, 4) === 'ftyp',
                $path . ' is not a JPEG/PNG/WebP/AVIF stream: ' . bin2hex($magic)
            );
        }
    }

    /** Scheme and host of the site: page markup carries root-relative paths. */
    private function origin(): string
    {
        $parts = parse_url($this->base);
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
