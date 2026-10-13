<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Locates an uploaded original on disk from the path stored in images.original_path.
 *
 * Current installs store '/storage/originals/…'; legacy ones stored
 * '/media/originals/…' (under public/). The result is always confined to one
 * of those two directories, whatever the stored value says.
 */
final class OriginalImage
{
    public static function resolve(string $path, ?string $root = null): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }
        $root ??= dirname(__DIR__, 2);
        $directories = [$root . '/storage/originals', $root . '/public/media/originals'];
        $relative = ltrim($path, '/');
        $candidates = [$root . '/' . $relative, $root . '/public/' . $relative];
        foreach ($directories as $directory) {
            $candidates[] = $directory . '/' . basename($path);
        }
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real === false || !is_file($real)) {
                continue;
            }
            foreach ($directories as $directory) {
                $base = realpath($directory);
                if ($base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }
        return null;
    }
}
