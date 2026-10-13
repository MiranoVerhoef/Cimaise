<?php

declare(strict_types=1);

/** Fixture cleanup shared by tests that build a tree under the system temp directory. */
final class TestFilesystem
{
    /** Recursively delete $path, which must live inside the system temp directory. */
    public static function remove(string $path): void
    {
        $temp = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        if ($temp === false || $parent === false
            || ($parent !== $temp && !str_starts_with($parent, $temp . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Refusing to remove a path outside the temp directory: ' . $path);
        }
        if (is_link($path) || is_file($path)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture path, confined to the system temp directory by the check above.
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
