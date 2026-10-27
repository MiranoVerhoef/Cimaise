<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ImageProcessingLock
{
    /**
     * How long the CLI (worker, images:* commands) waits for another holder
     * of the same image before giving up. Web requests never wait: they must
     * not hang behind a generation that can take minutes.
     */
    public static float $cliWaitSeconds = 120.0;

    /**
     * Lock files live in their own directory: there is one per image ever
     * processed, and they must not slow down listings of the job directory.
     */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/storage/image-jobs/locks';
    }

    /**
     * Run $operation while holding the image's lock.
     *
     * @throws ImageProcessingBusy when another process keeps holding it.
     */
    public static function run(int $imageId, callable $operation): mixed
    {
        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create image lock directory');
        }
        // Never unlink lock files while the image exists: replacing an inode
        // would allow two owners.
        $lock = fopen($dir . '/' . $imageId . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open image processing lock');
        }
        try {
            $deadline = microtime(true) + (PHP_SAPI === 'cli' ? self::$cliWaitSeconds : 0.0);
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new ImageProcessingBusy("Image {$imageId} is already being processed; retry later");
                }
                usleep(250000);
            }
            return $operation();
        } finally {
            fclose($lock);
        }
    }
}
