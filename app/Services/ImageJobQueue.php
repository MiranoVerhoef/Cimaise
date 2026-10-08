<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use RuntimeException;

/** Durable jobs need no schema migration and survive PHP/container termination. */
final class ImageJobQueue
{
    private string $directory;

    public function __construct(private readonly Database $db, ?string $directory = null)
    {
        $this->directory = $directory ?? dirname(__DIR__, 2) . '/storage/image-jobs';
    }

    public function enqueue(int $imageId): void
    {
        $this->ensureDirectory();
        // Atomic creation; duplicate uploads/wakeups must not reset retry state.
        $path = $this->directory . '/' . $imageId . '.job';
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fclose($handle);
        } elseif (!is_file($path)) {
            throw new RuntimeException('Cannot persist image generation job');
        }
    }

    public function drain(int $limit = 10): int
    {
        clearstatcache();
        if (!is_dir($this->directory)) {
            return 0;
        }
        $lock = fopen($this->directory . '/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open image worker lock');
        }
        $failures = 0;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return 0;
            }
            (new SettingsService($this->db))->clearCache();
            $processed = 0;
            foreach (glob($this->directory . '/*.job') ?: [] as $path) {
                $state = json_decode((string)file_get_contents($path), true) ?: [];
                if ((int)($state['next_at'] ?? 0) > time()) {
                    continue;
                }
                if ($processed++ >= $limit) {
                    break;
                }
                $id = (int)basename($path, '.job');
                try {
                    $stmt = $this->db->pdo()->prepare('SELECT id FROM images WHERE id = ?');
                    $stmt->execute([$id]);
                    $exists = $stmt->fetchColumn();
                    $stmt->closeCursor();
                    if ($exists) {
                        $this->processImage($id);
                    }
                    if (!unlink($path)) {
                        throw new RuntimeException('Cannot complete image job');
                    }
                } catch (\Throwable $e) {
                    $failures++;
                    $attempt = min(10, (int)($state['attempts'] ?? 0) + 1);
                    $state = ['attempts' => $attempt, 'next_at' => time() + min(300, 2 ** $attempt), 'error' => $e->getMessage()];
                    // A crash during this update merely leaves an immediately retryable job.
                    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
                    Logger::warning('Image generation job will retry', ['image_id' => $id] + $state, 'upload');
                }
            }
        } finally {
            fclose($lock);
        }
        return $failures;
    }

    public function hasJobs(): bool
    {
        return (bool)glob($this->directory . '/*.job');
    }

    public function runSynchronously(int $id): void
    {
        $this->ensureDirectory();
        $lock = fopen($this->directory . '/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open image worker lock');
        }
        try {
            // Claim the queue before enqueueing so a background worker cannot
            // race this upload. A killed request leaves its job for recovery.
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire image worker lock');
            }
            $this->enqueue($id);
            $this->processImage($id);
            unlink($this->directory . '/' . $id . '.job');
        } finally {
            fclose($lock);
        }
    }

    public function processImage(int $id): void
    {
        $service = new UploadService($this->db);
        $stats = $service->generateVariantsForImage($id);
        if ($stats['failed'] > 0) {
            throw new RuntimeException($stats['failed'] . ' image variants failed');
        }
        $stmt = $this->db->pdo()->prepare('SELECT a.is_nsfw, a.password_hash FROM albums a JOIN images i ON i.album_id = a.id WHERE i.id = ?');
        $stmt->execute([$id]);
        $album = $stmt->fetch();
        $stmt->closeCursor();
        $protected = !empty($album['is_nsfw']) || !empty($album['password_hash']);
        $placeholder = $protected ? $service->generateBlurredVariant($id) : $service->generateLQIP($id);
        if ($placeholder === null) {
            throw new RuntimeException('Image placeholder generation failed');
        }
    }

    /** Called after Slim emits the final response, including middleware headers. */
    public function schedule(): void
    {
        static $scheduled = false;
        if ($scheduled || PHP_SAPI === 'cli' || !glob($this->directory . '/*.job')) {
            return;
        }
        $scheduled = true;
        register_shutdown_function(function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('apache_finish_request')) {
                apache_finish_request();
            }
            @set_time_limit(300);
            try {
                // Prefer a detached CLI worker where hosting permits it. The SAPI
                // fallback below still works with exec disabled or no CLI binary.
                $php = PHP_BINDIR . '/php';
                if (PHP_OS_FAMILY !== 'Windows' && is_executable($php) && function_exists('exec')) {
                    $console = dirname(__DIR__, 2) . '/bin/console';
                    exec(escapeshellarg($php) . ' ' . escapeshellarg($console) . ' images:work --watch > /dev/null 2>&1 &');
                }
                $this->drain();
            } catch (\Throwable $e) {
                Logger::warning('Image worker deferred until next request', ['error' => $e->getMessage()], 'upload');
            }
        });
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create image job directory');
        }
    }
}
