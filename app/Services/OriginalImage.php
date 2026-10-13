<?php
declare(strict_types=1);

namespace App\Services;

final class OriginalImage
{
    public static function resolve(string $path): ?string
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            return null;
        }
        $root = dirname(__DIR__, 2);
        $directories = [$root . '/storage/originals', $root . '/public/media/originals'];
        $candidates = [$root . '/' . ltrim($path, '/'), $root . '/public/' . ltrim($path, '/')];
        foreach ($directories as $directory) {
            $candidates[] = $directory . '/' . basename($path);
        }
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if (!$real || !is_file($real)) { continue; }
            foreach ($directories as $directory) {
                $base = realpath($directory);
                if ($base && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
        }
        return null;
    }
}
