<?php
declare(strict_types=1);

// Run using the published test.12 image and isolated Docker volumes.
require '/var/www/html/vendor/autoload.php';
function envv(string $key, mixed $default = null): mixed { return $default; }
$root = '/var/www/html';
$db = new App\Support\Database(database: "$root/database/baseline-upgrade.sqlite", isSqlite: true);
$db->pdo()->exec(file_get_contents("$root/database/schema.sqlite.sql"));
$db->execute("INSERT INTO albums(id,title,slug,category_id,is_published,custom_cameras) VALUES(765432,'Retained album','baseline-upgrade',1,1,'Retained camera')");
$settings = new App\Services\SettingsService($db);
foreach ([
    'image.formats' => ['jpg' => true, 'webp' => true, 'avif' => true],
    'image.breakpoints' => ['sm' => 48, 'md' => 96, 'lg' => 144],
    'image.variants_async' => false,
    'album.765432.show_equipment' => false,
    'home.template' => 'classic', 'home.hero_enabled' => false,
    'home.hero_show_title' => false, 'home.hero_show_text' => true,
    'home.gallery_per_album' => 2, 'site.title' => 'Retained site title',
    'site.logo' => '/media/upgrade-light.png', 'site.logo_dark' => '/media/upgrade-dark.png',
    'about.menu_label' => 'Contact', 'about.slug' => 'contact',
    'social.profiles' => ['instagram' => 'https://example.test/retained'],
    'typography.custom_fonts' => ['custom-0123456789abcdef' => ['name' => 'Retained font', 'weights' => [400], 'files' => [400 => str_repeat('a', 64) . '.woff2'], 'type' => 'sans']],
    'maintenance.enabled' => false, 'maintenance.show_admin_login' => false, 'maintenance.show_logo' => true,
] as $key => $value) { $settings->set($key, $value); }
foreach (['upgrade-light.png', 'upgrade-dark.png'] as $name) {
    $logo = imagecreatetruecolor(20, 20);
    imagepng($logo, "$root/public/media/$name");
    imagedestroy($logo);
}
App\Services\ImagesService::ensureDir("$root/storage/fonts");
copy("$root/public/fonts/inter/inter-400.woff2", "$root/storage/fonts/" . str_repeat('a', 64) . '.woff2');
$upload = new App\Services\UploadService($db);
$ids = [];
foreach ([false, true] as $async) {
    $settings->set('image.variants_async', $async);
    $photo = imagecreatetruecolor(180, 120);
    imagefill($photo, 0, 0, imagecolorallocate($photo, $async ? 128 : 32, 40, 80));
    imagejpeg($photo, "$root/storage/tmp/upgrade-photo.jpg");
    imagedestroy($photo);
    $uploaded = $upload->ingestAlbumUpload(765432, ['tmp_name' => "$root/storage/tmp/upgrade-photo.jpg", 'error' => 0]);
    $ids[] = (int)$uploaded['id'];
}
$files = [];
foreach (array_merge(glob("$root/storage/originals/*") ?: [], glob("$root/public/media/*.*") ?: [], glob("$root/storage/fonts/*") ?: []) as $file) {
    if (is_file($file) && !str_ends_with($file, '/.htaccess')) { $files[$file] = hash_file('sha256', $file); }
}
$snapshot = [
    'settings' => $db->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR),
    'images' => $db->query('SELECT id, original_path, file_hash, width, height, mime FROM images ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'files' => $files, 'queued_image' => $ids[1],
];
$snapshot['code'] = [];
foreach ([
    'app/Services/UploadService.php', 'app/Services/ImageJobQueue.php',
    'app/Services/OriginalImage.php', 'app/Services/ImageGenerationRevision.php',
    'app/Services/AlbumStorageService.php', 'app/Services/AnalyticsService.php',
    'app/Services/HomeImageService.php', 'app/Services/TypographyService.php',
    'app/Controllers/Admin/UploadController.php', 'app/Controllers/Admin/MediaController.php',
    'app/Controllers/Admin/PagesController.php', 'app/Controllers/Admin/TypographyController.php',
    'resources/admin.js', 'public/assets/image-job-progress.js',
    'public/assets/js/image-version-notice.js', 'app/Views/frontend/_site_logo.twig',
    'app/Views/frontend/_download_runtime.twig',
] as $file) {
    if (!is_file("$root/$file")) { throw new RuntimeException("Missing parity source $file"); }
    $snapshot['code'][$file] = hash_file('sha256', "$root/$file");
}
file_put_contents("$root/storage/tmp/baseline-snapshot.json", json_encode($snapshot, JSON_THROW_ON_ERROR));
file_put_contents("$root/storage/.env", "DB_CONNECTION=sqlite\nDB_DATABASE=database/baseline-upgrade.sqlite\nAPP_ENV=testing\nAPP_DEBUG=false\n");
file_put_contents("$root/storage/tmp/.installed", 'baseline upgrade fixture');
echo "Seeded published test.12 volume with saved preferences, originals, variants and a pending job.\n";
