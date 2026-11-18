<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final class AlbumLayoutService
{
    public function __construct(private readonly Database $db)
    {
    }

    // Only the authenticated editor initializes storage on older installations.
    // Fresh installs and Docker upgrades receive the same table through the schema/migration.
    public function initialize(int $albumId): void
    {
        $engine = $this->db->isSqlite() ? 'sqlite' : 'mysql';
        $sql = file_get_contents(dirname(__DIR__, 2) . "/database/migrations/migrate_1.4.31_{$engine}.sql");
        $this->db->pdo()->exec((string) $sql);
        $this->db->execute($this->db->insertIgnoreKeyword() . ' INTO album_layouts (album_id) VALUES (?)', [$albumId]);
    }

    public function state(int $albumId): array
    {
        try {
            $row = $this->db->query('SELECT draft_json, published_json, revision FROM album_layouts WHERE album_id = ?', [$albumId])->fetch();
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S02' && !str_contains($error->getMessage(), 'no such table: album_layouts')) {
                throw $error;
            }
            $row = false;
        }

        return [
            'revision' => (int) ($row['revision'] ?? 0),
            'draft' => isset($row['draft_json']) ? json_decode($row['draft_json'], true, flags: JSON_THROW_ON_ERROR) : null,
            'published' => isset($row['published_json']) ? json_decode($row['published_json'], true, flags: JSON_THROW_ON_ERROR) : null,
        ];
    }

    public function normalize(array $layout, array $allowedImageIds): array
    {
        $blocks = $layout['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > 200) {
            throw new InvalidArgumentException('A layout must contain at most 200 blocks.');
        }
        $allowed = array_fill_keys($allowedImageIds, true);
        $seen = [];
        $result = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || !in_array($block['type'] ?? '', ['photo', 'grid', 'text'], true)) {
                throw new InvalidArgumentException('Unknown layout block.');
            }
            $id = $block['id'] ?? '';
            if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $id) || isset($seen[$id])) {
                throw new InvalidArgumentException('Each block needs a unique ID.');
            }
            $seen[$id] = true;
            $item = [
                'id' => $id,
                'type' => $block['type'],
                'width' => $this->integer($block, 'width', 100, 10, 100),
                'max_width' => $this->integer($block, 'max_width', 0, 0, 3840),
                'align' => in_array($block['align'] ?? 'center', ['left', 'center', 'right'], true) ? ($block['align'] ?? 'center') : 'center',
                'top' => $this->integer($block, 'top', 0, 0, 500),
                'bottom' => $this->integer($block, 'bottom', 20, 0, 500),
            ];
            if ($block['type'] === 'text') {
                if (!is_string($block['text'] ?? '') || strlen($block['text'] ?? '') > 20000) {
                    throw new InvalidArgumentException('Text blocks must be at most 20,000 bytes.');
                }
                $item['text'] = trim($block['text'] ?? '');
            } else {
                $images = $block['images'] ?? [];
                if (!is_array($images) || !array_is_list($images) || count($images) > 2000
                    || ($block['type'] === 'photo' && count($images) !== 1)) {
                    throw new InvalidArgumentException('Select one image for a photo or up to 2,000 for a grid.');
                }
                foreach ($images as $imageId) {
                    if (!is_int($imageId) || !isset($allowed[$imageId])) {
                        throw new InvalidArgumentException('Selected photos must belong to this album. Refresh the editor if a photo was deleted.');
                    }
                }
                $item['images'] = array_values(array_unique($images));
                $item['columns'] = $this->integer($block, 'columns', 3, 1, 8);
                $item['gap'] = $this->integer($block, 'gap', 8, 0, 100);
            }
            $result[] = $item;
        }
        if (!is_bool($layout['include_remaining'] ?? true)) {
            throw new InvalidArgumentException('Invalid remaining photos setting.');
        }
        return ['version' => 1, 'include_remaining' => $layout['include_remaining'] ?? true, 'blocks' => $result];
    }

    private function integer(array $block, string $key, int $default, int $min, int $max): int
    {
        $value = $block[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("Invalid {$key} value.");
        }
        return $value;
    }

    public function save(int $albumId, int $revision, ?array $layout, string $action): int
    {
        if (!in_array($action, ['draft', 'publish', 'default'], true)) {
            throw new InvalidArgumentException('Unknown save action.');
        }
        $json = $layout === null ? null : json_encode($layout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($action !== 'default' && $json === null) {
            throw new InvalidArgumentException('A layout is required.');
        }
        $assignments = match ($action) {
            'publish' => 'draft_json = ?, published_json = ?,',
            'draft' => 'draft_json = ?,',
            'default' => 'published_json = NULL,',
        };
        $params = match ($action) {
            'publish' => [$json, $json],
            'draft' => [$json],
            'default' => [],
        };
        $params[] = $albumId;
        $params[] = $revision;
        $count = $this->db->execute("UPDATE album_layouts SET {$assignments} revision = revision + 1 WHERE album_id = ? AND revision = ?", $params);
        if ($count !== 1) {
            throw new RuntimeException('This layout changed in another tab. Reload before saving.', 409);
        }
        return $revision + 1;
    }

    // Resolve against already-authorized album images. Deleted photos disappear,
    // and new uploads can join the remaining-photos grid automatically.
    public function resolve(array $layout, array $images): array
    {
        $index = array_column($images, null, 'id');
        $used = [];
        $blocks = [];
        foreach ($layout['blocks'] as $block) {
            if ($block['type'] !== 'text') {
                $selected = [];
                foreach ($block['images'] as $id) {
                    if (isset($index[$id])) {
                        $selected[] = $index[$id];
                        $used[$id] = true;
                    }
                }
                if ($selected === []) {
                    continue;
                }
                $block['photos'] = $selected;
            }
            $blocks[] = $block;
        }
        return [
            'blocks' => $blocks,
            'remaining' => $layout['include_remaining']
                ? array_values(array_filter($images, static fn (array $image): bool => !isset($used[$image['id']])))
                : [],
        ];
    }
}
