<?php

declare(strict_types=1);

use App\Controllers\Frontend\PageController;
use App\Middlewares\CacheMiddleware;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class CacheRevalidationTest extends TestCase
{
    private Database $db;
    private SettingsService $settings;
    private PageCacheService $cache;
    private array $session;
    private mixed $settingsSnapshot;
    private string $scriptName;

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $this->scriptName = $_SERVER['SCRIPT_NAME'];
        $_SESSION = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->db->pdo()->exec("INSERT INTO categories (id,name,slug) VALUES (99999,'Review','review');
            INSERT INTO albums (id,title,slug,category_id,is_published,excerpt) VALUES (99999,'Review','review',99999,1,'NEW')");
        $this->settings = new SettingsService($this->db);
        $property->setValue(null, $this->settings->defaults() + ['cache.storage_backend' => 'database']);
        $this->cache = new PageCacheService($this->settings, $this->db);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        $_SESSION = $this->session;
        $_SERVER['SCRIPT_NAME'] = $this->scriptName;
    }

    private function entry(string $excerpt): array
    {
        return ['template_file' => 'review.twig', 'data' => ['album' => ['excerpt' => $excerpt]]];
    }

    private function handler(Closure $render): RequestHandlerInterface
    {
        return new class($render) implements RequestHandlerInterface {
            public function __construct(private Closure $render) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->render)($request);
            }
        };
    }

    public function testStaleAlbumDoesNotReceiveTheValidatorOfAConcurrentRebuild(): void
    {
        $key = PageCacheService::albumPageKey(99999);
        $this->cache->set($key, $this->entry('OLD'));
        $oldEtag = '"' . $this->cache->getHash($key) . '"';
        $this->cache->invalidate($key);
        $path = dirname(__DIR__, 2) . '/storage/tmp/page_regen_' . (crc32($key) % 64) . '.lock';
        $existed = is_file($path);
        $lock = fopen($path, 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            // Deterministic interleaving: request A finishes rebuilding while
            // request B renders the stale snapshot after losing the lock.
            $twig = new Twig(new ArrayLoader(['review.twig' => '{{ finish_rebuild() }}<p>{{ album.excerpt }}</p>']));
            $twig->getEnvironment()->addFunction(new TwigFunction('finish_rebuild', function () use ($key, $lock): string {
                $this->cache->set($key, $this->entry('NEW'));
                flock($lock, LOCK_UN);
                return '';
            }));
            $controller = new PageController($this->db, $twig);
            $handler = $this->handler(fn ($request) => $controller->album($request, new Response(), ['slug' => 'review']));
            $middleware = new CacheMiddleware($this->settings, $this->db);
            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/album/review');
            $response = $middleware->process($request->withHeader('If-None-Match', $oldEtag), $handler);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('<p>OLD</p>', (string) $response->getBody());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertFalse($response->hasHeader('ETag'));

            $next = $middleware->process($request->withHeader('If-None-Match', $oldEtag), $handler);
            self::assertSame(200, $next->getStatusCode());
            self::assertSame('<p>NEW</p>', (string) $next->getBody());
            $unchanged = $middleware->process($request->withHeader('If-None-Match', $next->getHeaderLine('ETag')), $handler);
            self::assertSame(304, $unchanged->getStatusCode());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if (!$existed) {
                unlink($path);
            }
        }
    }

    public function testFreshSnapshotIsNotLabelledWithAReplacementCacheHash(): void
    {
        $key = PageCacheService::albumPageKey(99999);
        $this->cache->set($key, $this->entry('OLD'));
        $middleware = new CacheMiddleware($this->settings, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/album/review');
        $response = $middleware->process($request, $this->handler(function () use ($key) {
            $this->cache->set($key, $this->entry('NEW'));
            $response = new Response();
            $response->getBody()->write('<p>OLD</p>');
            return $response;
        }));
        self::assertNotSame('"' . $this->cache->getHash($key) . '"', $response->getHeaderLine('ETag'));
        self::assertSame('"' . sha1('<p>OLD</p>') . '"', $response->getHeaderLine('ETag'));
        $next = $middleware->process($request->withHeader('If-None-Match', $response->getHeaderLine('ETag')),
            $this->handler(static function () {
                $response = new Response();
                $response->getBody()->write('<p>NEW</p>');
                return $response;
            }));
        self::assertSame(200, $next->getStatusCode());
        self::assertSame('<p>NEW</p>', (string) $next->getBody());
    }

    public function testEntryStoredDuringRenderingDoesNotLabelABodyRenderedWithoutIt(): void
    {
        // No valid entry when the request starts; another request stores a
        // newer one while this body is rendered from older data.
        $key = PageCacheService::albumPageKey(99999);
        $middleware = new CacheMiddleware($this->settings, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/album/review');
        $response = $middleware->process($request, $this->handler(function () use ($key) {
            $this->cache->set($key, $this->entry('NEW'));
            $response = new Response();
            $response->getBody()->write('<p>OLD</p>');
            return $response;
        }));
        self::assertNotSame('"' . $this->cache->getHash($key) . '"', $response->getHeaderLine('ETag'));
        self::assertSame('"' . sha1('<p>OLD</p>') . '"', $response->getHeaderLine('ETag'));
    }

    public function testGalleriesValidatorComesFromTheActiveTemplateEntry(): void
    {
        // A non-classic listing template renders from its own entry; a leftover
        // classic entry must not validate that page.
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $property->setValue(null, ['galleries.template' => 'mosaic'] + $property->getValue());
        $this->cache->set('galleries', ['data' => ['albums' => ['classic']]]);
        $classicEtag = '"' . $this->cache->getHash('galleries') . '"';
        $middleware = new CacheMiddleware($this->settings, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/galleries')
            ->withHeader('If-None-Match', $classicEtag);
        $rendered = false;
        $response = $middleware->process($request, $this->handler(static function () use (&$rendered) {
            $rendered = true;
            $response = new Response();
            $response->getBody()->write('<p>MOSAIC</p>');
            return $response;
        }));
        self::assertTrue($rendered, 'the classic entry must not answer 304 for another template');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('galleries_mosaic', PageCacheService::galleriesPageKey('mosaic'));
        self::assertSame('galleries', PageCacheService::galleriesPageKey('classic'));
    }

    public function testQueryStringRequestsDoNotBorrowTheListingValidator(): void
    {
        // A filtered listing is not rendered from the cached entry.
        $this->cache->set('galleries', ['data' => ['albums' => ['all']]]);
        $etag = '"' . $this->cache->getHash('galleries') . '"';
        $middleware = new CacheMiddleware($this->settings, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/galleries?category=1')
            ->withQueryParams(['category' => '1'])
            ->withHeader('If-None-Match', $etag);
        $response = $middleware->process($request, $this->handler(static function () {
            $response = new Response();
            $response->getBody()->write('<p>FILTERED</p>');
            return $response;
        }));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"' . sha1('<p>FILTERED</p>') . '"', $response->getHeaderLine('ETag'));
    }

    public function testExpiredFileCacheCannotBypassTheHandlerWith304(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('get')->willReturnCallback(static fn ($key, $default = null) =>
            $key === 'cache.storage_backend' ? 'file' : $default);
        $middleware = new CacheMiddleware($settings, $this->db);
        $cache = $this->createMock(PageCacheService::class);
        $cache->expects(self::once())->method('isExpired')->with('home')->willReturn(true);
        $cache->expects(self::never())->method('getCacheFilePath');
        (new ReflectionProperty(CacheMiddleware::class, 'pageCacheService'))->setValue($middleware, $cache);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/')
            ->withHeader('If-None-Match', '*');
        $response = $middleware->process($request, $this->handler(static fn () =>
            (new Response())->withHeader('Cache-Control', 'no-store')));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDisabledPageCacheDoesNotProvideAnEarlyValidator(): void
    {
        $this->cache->set('home', ['title' => 'Old']);
        $settings = $this->createMock(SettingsService::class);
        $settings->method('get')->willReturnCallback(static fn ($key, $default = null) =>
            $key === 'cache.pages_enabled' ? false : $default);
        $middleware = new CacheMiddleware($settings, $this->db);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://review.test/')
            ->withHeader('If-None-Match', '*');
        $response = $middleware->process($request, $this->handler(static fn () =>
            (new Response())->withHeader('Cache-Control', 'no-store')));
        self::assertSame(200, $response->getStatusCode());
    }
}
