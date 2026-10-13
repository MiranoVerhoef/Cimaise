<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Updater;
use RuntimeException;

/** Apply the image's migrations to a database retained by a Docker volume. */
final class ContainerMigrationService
{
    private const VERSION_KEY = 'docker.schema_version';
    // Official images started at 1.4.14, whose schema includes 1.4.13.
    // Do not replay older table rebuilds on an unversioned Docker database.
    private const LEGACY_VERSION = '1.4.13';

    public function __construct(private readonly Database $db, private readonly string $migrationsPath)
    {
    }

    /** @return string[] Applied migration filenames. */
    public function migrate(string $targetVersion): array
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $targetVersion)) {
            throw new RuntimeException('Invalid image version');
        }
        $stmt = $this->db->query('SELECT `value` FROM settings WHERE `key` = ?', [self::VERSION_KEY]);
        $stored = $stmt->fetchColumn();
        $stmt->closeCursor();
        $fromVersion = $stored === false ? self::LEGACY_VERSION : json_decode((string) $stored, true);
        if (!is_string($fromVersion) || !preg_match('/^\d+\.\d+\.\d+$/', $fromVersion)) {
            throw new RuntimeException('Invalid stored Docker schema version');
        }
        if (version_compare($fromVersion, $targetVersion, '>')) {
            throw new RuntimeException('The database schema is newer than this image; use the newer image');
        }
        if ($fromVersion === $targetVersion) {
            return [];
        }
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException('Image migration directory is missing');
        }

        $result = (new Updater($this->db))->runMigrations($fromVersion, $targetVersion, $this->migrationsPath);
        if (!$result['success']) {
            throw new RuntimeException('Database migration failed: ' . (string) $result['error']);
        }

        // Discard presentation data from the previous image before marking the
        // upgrade complete. File/query/Twig caches are cleared by the entrypoint.
        $this->db->execute('DELETE FROM page_cache');
        $replace = $this->db->replaceKeyword();
        $this->db->execute("{$replace} INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?)",
            [self::VERSION_KEY, json_encode($targetVersion), 'string']);

        return $result['executed'];
    }
}
