<?php
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
function envv(string $key, mixed $default = null): mixed { return $default; }
function preserved(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}
$root = '/var/www/html';
$db = new App\Support\Database(database: "$root/database/baseline-upgrade.sqlite", isSqlite: true);
$snapshot = json_decode(file_get_contents("$root/storage/tmp/baseline-snapshot.json"), true, flags: JSON_THROW_ON_ERROR);
foreach ($snapshot['code'] as $file => $hash) {
    preserved(is_file("$root/$file") && hash_file('sha256', "$root/$file") === $hash, "test.12 implementation is identical: $file");
}
$settings = $db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($snapshot['settings'] as $key => $value) {
    preserved(($settings[$key] ?? null) === $value, "retained setting $key");
}
preserved($db->query('SELECT id, original_path, file_hash, width, height, mime FROM images ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $snapshot['images'], 'image identities, original paths and metadata are unchanged');
preserved((int)$db->query('SELECT show_equipment FROM albums WHERE id = 765432')->fetchColumn() === 0, 'hidden equipment stays hidden after migration');
preserved($db->query('SELECT custom_cameras FROM albums WHERE id = 765432')->fetchColumn() === 'Retained camera', 'equipment metadata survives migration');
foreach ($snapshot['files'] as $file => $hash) {
    preserved(is_file($file) && hash_file('sha256', $file) === $hash, 'retained bytes: ' . basename($file));
}
$id = (int)$snapshot['queued_image'];
preserved(!is_file("$root/storage/image-jobs/$id.job"), 'queued test.12 job completes on the new worker');
foreach (['sm', 'md', 'lg'] as $size) {
    foreach (['jpg', 'webp', 'avif'] as $format) {
        preserved(is_file("$root/public/media/{$id}_$size.$format"), "pending image gains $size.$format");
    }
}
echo "Published test.12 to new-baseline Docker upgrade checks passed.\n";
