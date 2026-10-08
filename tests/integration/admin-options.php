<?php
declare(strict_types=1);

// Disposable checkout only: exercises real SQLite queries and cache backends.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
function envv(string $key, mixed $default = null): mixed { return $default; }
function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n";
}
$root = dirname(__DIR__, 2);
$path = $root . '/storage/tmp/admin-options-' . bin2hex(random_bytes(5)) . '.sqlite';
$db = new App\Support\Database(database: $path, isSqlite: true);
$settings = new App\Services\SettingsService($db);
try {
    $db->pdo()->exec(file_get_contents($root . '/database/schema.sqlite.sql'));
    $settings->clearCache();
    foreach ([1, 2, 3] as $album) {
        $db->execute('INSERT INTO albums(id,title,slug,category_id,is_published) VALUES(?,?,?,?,1)', [$album, 'Test', 'options-' . $album, 1]);
        for ($index = 0; $index < 10; $index++) {
            $db->execute('INSERT INTO images(album_id,original_path,file_hash,width,height,mime,sort_order) VALUES(?,?,?,?,?,?,?)', [$album, '/storage/originals/test.jpg', $album . '-' . $index, 180, 120, 'image/jpeg', $index]);
        }
    }
    $settings->set('home.template', 'classic');
    $settings->set('home.gallery_per_album', 2);
    $images = new App\Services\HomeImageService($db);
    $first = $images->getInitialImages(3);
    check($first['totalImages'] === 6, 'Classic totals respect the per-album cap');
    $seen = $first['images'];
    for ($batch = 0; $batch < 10; $batch++) {
        $next = $images->getMoreImages(array_column($seen, 'id'), array_values(array_unique(array_column($seen, 'album_id'))), 2);
        $seen = array_merge($seen, $next['images']);
        if (!$next['hasMore']) { break; }
    }
    check(count($seen) === 6 && count(array_unique(array_column($seen, 'id'))) === 6, 'progressive batches exhaust capped photos without duplicates');
    check(array_values(array_unique(array_count_values(array_column($seen, 'album_id')))) === [2], 'no album exceeds its unique-photo limit across batches');
    $settings->set('home.gallery_per_album', 0);
    check($images->getInitialImages(3)['totalImages'] === 30, 'zero preserves the existing selection');
    $settings->set('home.gallery_per_album', 2);
    $settings->set('home.template', 'modern');
    check($images->getInitialImages(3)['totalImages'] === 30, 'Classic cap does not truncate other templates');

    $_SESSION = ['admin_id' => 1];
    foreach (['database', 'file'] as $backend) {
        $settings->set('cache.storage_backend', $backend);
        $cache = new App\Services\PageCacheService($settings, $db);
        $cache->set('home', ['old' => true]);
        $cache->set('galleries', ['old' => true]);
        $cache->set('album:options-1', ['old' => true]);
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/social');
        $handler = new class implements Psr\Http\Server\RequestHandlerInterface {
            public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface {
                return new Slim\Psr7\Response(302);
            }
        };
        (new App\Middlewares\CacheMiddleware($settings, $db))->process($request, $handler);
        check($cache->get('home', true) === null && $cache->get('galleries', true) === null && $cache->get('album:options-1', true) === null,
            "successful shared settings edits hard-purge {$backend} pages including stale entries");
    }
} finally {
    $settings->clearCache();
    unset($images, $cache, $settings, $db);
    @unlink($path); @unlink($path . '-wal'); @unlink($path . '-shm');
}
