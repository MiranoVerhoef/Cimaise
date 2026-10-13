<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Updater;
use RuntimeException;

/**
 * Apply the image's migrations to a database retained by a Docker volume.
 *
 * Two values are kept in `settings`:
 *  - docker.schema_version: the newest migration applied to this database. It
 *    only moves when a migration runs, so rolling back to an image with the
 *    same schema is allowed and only a real schema downgrade is refused.
 *  - docker.image_version: the image that last started on this database, used
 *    to discard the previous image's cached page data exactly once.
 */
final class ContainerMigrationService
{
    private const SCHEMA_KEY = 'docker.schema_version';
    private const IMAGE_KEY = 'docker.image_version';
    // Official images started at 1.4.14, whose schema includes 1.4.13.
    // Do not replay older table rebuilds on an unversioned Docker database.
    private const LEGACY_VERSION = '1.4.13';
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';

    public function __construct(private readonly Database $db, private readonly string $migrationsPath)
    {
    }

    /** @return string[] Applied migration filenames. */
    public function migrate(string $targetVersion): array
    {
        if (!preg_match(self::VERSION_PATTERN, $targetVersion)) {
            throw new RuntimeException('Invalid image version');
        }
        $schemaVersion = $this->stored(self::SCHEMA_KEY) ?? self::LEGACY_VERSION;
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException('Image migration directory is missing');
        }
        $imageSchemaVersion = $this->newestImageMigration($targetVersion);
        if (version_compare($schemaVersion, $imageSchemaVersion, '>')) {
            throw new RuntimeException('The database schema is newer than this image; use the newer image');
        }

        $executed = [];
        if ($schemaVersion !== $imageSchemaVersion) {
            $result = (new Updater($this->db))->runMigrations($schemaVersion, $targetVersion, $this->migrationsPath);
            if (!$result['success']) {
                throw new RuntimeException('Database migration failed: ' . (string) $result['error']);
            }
            $executed = $result['executed'];
        }

        // Discard presentation data from the previous image before marking the
        // start complete. File/query/Twig caches are cleared by the entrypoint.
        if ($this->stored(self::IMAGE_KEY) !== $targetVersion) {
            $this->db->execute('DELETE FROM page_cache');
        }
        $this->store(self::SCHEMA_KEY, $imageSchemaVersion);
        $this->store(self::IMAGE_KEY, $targetVersion);

        return $executed;
    }

    /** Newest migration this image ships for the current engine, up to its own version. */
    private function newestImageMigration(string $targetVersion): string
    {
        $newest = self::LEGACY_VERSION;
        $engine = $this->db->isSqlite() ? 'sqlite' : 'mysql';
        foreach (glob($this->migrationsPath . "/migrate_*_{$engine}.sql") ?: [] as $file) {
            if (!preg_match('/^migrate_(\d+\.\d+\.\d+)_/', basename($file), $m)) {
                continue;
            }
            if (version_compare($m[1], $targetVersion, '<=') && version_compare($m[1], $newest, '>')) {
                $newest = $m[1];
            }
        }
        return $newest;
    }

    private function stored(string $key): ?string
    {
        $stmt = $this->db->query('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        $raw = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($raw === false) {
            return null;
        }
        $value = json_decode((string) $raw, true);
        if (!is_string($value) || !preg_match(self::VERSION_PATTERN, $value)) {
            throw new RuntimeException('Invalid stored Docker version (' . $key . ')');
        }
        return $value;
    }

    private function store(string $key, string $version): void
    {
        $replace = $this->db->replaceKeyword();
        $this->db->execute(
            "{$replace} INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?)",
            [$key, json_encode($version), 'string']
        );
    }
}
