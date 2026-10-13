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
        $root = realpath($cacheRoot);
        if ($root === false) {
            return;
        }
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->getFilename() === $keep) {
                continue;
            }
            self::remove($entry, $root);
        }
    }

    private static function remove(\SplFileInfo $entry, string $root): void
    {
        $path = $entry->getPathname();
        // The parent is resolved, the entry itself is not: a link is removed
        // as a link and never followed out of the cache directory.
        $parent = realpath(dirname($path));
        if ($parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            return;
        }
        if ($entry->isLink() || !$entry->isDir()) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- $path comes from iterating the cache directory and its parent is realpath-confined to it directly above; no request input reaches this method.
            @unlink($path);
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $child) {
            self::remove($child, $root);
        }
        @rmdir($path);
    }
}
