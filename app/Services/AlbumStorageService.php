<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class AlbumStorageService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Count existing media files once per album, including shared originals. */
    public function usage(array $albumIds): array
    {
        $albumIds = array_values(array_unique(array_map('intval', $albumIds)));
        if ($albumIds === []) {
            return [];
        }
        $usage = array_fill_keys($albumIds, ['original_bytes' => 0, 'variant_bytes' => 0, 'total_bytes' => 0]);
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $stmt = $this->pdo->prepare("SELECT album_id, original_path FROM images WHERE album_id IN ($placeholders)");
        $stmt->execute($albumIds);
        $originals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $stmt = $this->pdo->prepare("SELECT i.album_id, v.path FROM image_variants v JOIN images i ON i.id = v.image_id WHERE i.album_id IN ($placeholders)");
        $stmt->execute($albumIds);
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // Release SQLite read cursors before inspecting files; never move media here.
        $seen = [];
        $count = static function (int $albumId, ?string $file, string $kind) use (&$usage, &$seen): void {
            if ($file === null || isset($seen[$albumId][$file])) {
                return;
            }
            $seen[$albumId][$file] = true;
            $bytes = @filesize($file);
            if ($bytes !== false) {
                $usage[$albumId][$kind] += $bytes;
                $usage[$albumId]['total_bytes'] += $bytes;
            }
        };
        foreach ($originals as $row) {
            $count((int)$row['album_id'], OriginalImage::resolve((string)$row['original_path']), 'original_bytes');
        }
        $root = dirname(__DIR__, 2);
        $directories = [$root . '/public/media', $root . '/storage/protected-media'];
        foreach ($variants as $row) {
            $path = (string)$row['path'];
            if (str_contains($path, '..') || str_contains($path, '\\')
                || !preg_match('~^/?(?:public/)?media/(?:protected/)?(\d+_[a-z0-9_-]+\.(?:jpg|jpeg|webp|avif|jxl|png))$~iD', $path, $match)) {
                continue;
            }
            foreach ($directories as $directory) {
                $base = realpath($directory);
                $file = realpath($directory . '/' . $match[1]);
                if ($base && $file && is_file($file) && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                    $count((int)$row['album_id'], $file, 'variant_bytes');
                }
            }
        }
        return $usage;
    }
}
