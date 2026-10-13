<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Compiled-template cache location, one directory per shipped release.
 *
 * Twig does not revalidate compiled templates in production, so templates
 * compiled by a previous release would keep being served after the files were
 * replaced (manual deploy, restored backup, persistent volume). Keying the
 * directory on version.json makes every release compile its own templates.
 */
final class TwigCache
{
    /** @return string|null Writable cache directory, or null when caching is impossible. */
    public static function directory(string $projectRoot): ?string
    {
        $cacheRoot = $projectRoot . '/storage/cache/twig';
        $release = substr(hash('sha256', (string) @file_get_contents($projectRoot . '/version.json')), 0, 16);
        $directory = $cacheRoot . '/' . $release;

        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                return null;
            }
            // First request of this release: drop what earlier releases compiled.
            self::prune($cacheRoot, $release);
        }

        return is_writable($directory) ? $directory : null;
    }

    private static function prune(string $cacheRoot, string $keep): void
    {
        foreach (new \FilesystemIterator($cacheRoot, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->getFilename() === $keep) {
                continue;
            }
            self::remove($entry);
        }
    }

    private static function remove(\SplFileInfo $entry): void
    {
        // Never descend through a link: only the link itself is removed.
        if ($entry->isLink() || !$entry->isDir()) {
            @unlink($entry->getPathname());
            return;
        }
        foreach (new \FilesystemIterator($entry->getPathname(), \FilesystemIterator::SKIP_DOTS) as $child) {
            self::remove($child);
        }
        @rmdir($entry->getPathname());
    }
}
