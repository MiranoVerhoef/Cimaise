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

    public function trackUpload(int $imageId, string $token): void
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $token)) {
            $this->report($imageId, ['upload_token' => $token, 'state' => 'uploading', 'completed' => 0, 'total' => 0, 'current' => '']);
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
                    $this->report($id, ['state' => 'retrying', 'next_at' => $state['next_at'], 'attempts' => $attempt]);
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
        $this->enqueue($id);
        $lock = fopen($this->directory . '/worker.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open image worker lock');
        }
        try {
            // Persist before waiting: a killed upload still leaves recovery work.
            // The queue lock lets an already-running worker finish before us.
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire image worker lock');
            }
            $this->processImage($id);
            $path = $this->directory . '/' . $id . '.job';
            if (is_file($path)) {
                unlink($path);
            }
        } finally {
            fclose($lock);
        }
    }

    public function processImage(int $id): void
    {
        $service = new UploadService($this->db);
        $this->report($id, ['state' => 'processing', 'next_at' => 0, 'completed' => 0, 'total' => 0, 'current' => '']);
        $stats = $service->generateVariantsForImage($id, progress: function (int $completed, int $total, string $current) use ($id): void {
            // Reserve one step for the placeholder; 100% means the entire job finished.
            $this->report($id, ['state' => 'processing', 'completed' => $completed, 'total' => $total + 1, 'current' => $current]);
        });
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
        (new PageCacheService(new SettingsService($this->db), $this->db))->clearAll();
        ImageGenerationRevision::advance();
        $this->report($id, ['state' => 'complete', 'completed' => $stats['generated'] + $stats['skipped'] + 1, 'current' => '']);
    }

    /** Files keep frequent progress updates out of SQLite. No paths/errors are exposed. */
    public function status(): array
    {
        $jobs = [];
        $total = null;
        $expectedSteps = function () use (&$total): int {
            if ($total === null) {
                $configuration = UploadService::variantConfiguration(new SettingsService($this->db));
                $total = count($configuration['breakpoints']) * count($configuration['formats']) + 1;
            }
            return $total;
        };
        foreach (glob($this->directory . '/*.progress') ?: [] as $path) {
            $id = (int)basename($path, '.progress');
            $state = json_decode((string)@file_get_contents($path), true);
            if (!is_array($state)) {
                continue;
            }
            $pending = is_file($this->directory . '/' . $id . '.job');
            // Correlate an upload before its HTTP response, including synchronous generation.
            if (($state['state'] ?? '') === 'uploading' && (int)($state['updated_at'] ?? 0) >= time() - 120) {
                $pending = true;
            }
            if ($pending && ($state['state'] ?? '') === 'complete') {
                $state = ['state' => 'queued', 'completed' => 0, 'total' => 0, 'current' => ''];
            }
            if (!$pending && (int)($state['updated_at'] ?? 0) < time() - 300) {
                continue;
            }
            if ($pending && empty($state['total'])) {
                $state['total'] = $expectedSteps();
            }
            $jobs[] = ['id' => $id, 'pending' => $pending] + $state;
        }
        // Include legacy job markers that predate progress reporting.
        $known = array_column($jobs, 'id');
        foreach (glob($this->directory . '/*.job') ?: [] as $path) {
            $id = (int)basename($path, '.job');
            if (!in_array($id, $known, true)) {
                $jobs[] = ['id' => $id, 'pending' => true, 'state' => 'queued', 'completed' => 0, 'total' => $expectedSteps(), 'current' => ''];
            }
        }
        return $jobs;
    }

    private function report(int $id, array $changes): void
    {
        $this->ensureDirectory();
        $path = $this->directory . '/' . $id . '.progress';
        $state = json_decode((string)@file_get_contents($path), true) ?: [];
        $state = array_replace($state, $changes, ['updated_at' => time()]);
        $temporary = $path . '.tmp';
        // Queue workers are serialized. Atomic rename gives readers a full snapshot.
        if (@file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) !== false) {
            @rename($temporary, $path);
        }
        // Bounded retention; active jobs are never removed.
        if (($changes['state'] ?? '') === 'complete') {
            foreach (glob($this->directory . '/*.progress') ?: [] as $old) {
                if (@filemtime($old) < time() - 300 && !is_file(substr($old, 0, -9) . '.job')) {
                    @unlink($old);
                }
            }
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
                    $this->drain();
                } else {
                    // Another upload can enqueue after a worker's directory scan.
                    // Keep waking the queue instead of abandoning jobs when its
                    // lock is held by a concurrent response's shutdown handler.
                    $deadline = time() + 280;
                    do {
                        $this->drain();
                        if (!$this->hasJobs()) {
                            break;
                        }
                        sleep(1);
                    } while (time() < $deadline);
                }
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
