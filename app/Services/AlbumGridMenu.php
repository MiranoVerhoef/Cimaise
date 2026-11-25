<?php

declare(strict_types=1);

namespace App\Services;

final class AlbumGridMenu
{
    public const SETTING = 'home.album_grid_menu';
    public const MAX_GROUPS = 12;

    public static function editorState(mixed $stored, array $categories): array
    {
        $byId = array_column($categories, null, 'id');
        if (!is_array($stored)) {
            $parents = array_filter($categories, static fn (array $row): bool => $row['parent_id'] === null);
            return [
                'links' => [],
                'groups' => [['label' => '', 'ids' => array_map('intval', array_column($parents, 'id'))]],
                'home_categories' => [],
            ];
        }

        $links = self::ids($stored['links'] ?? [], $byId);
        $seen = array_fill_keys($links, true);
        $groups = [];
        foreach (array_slice(is_array($stored['groups'] ?? null) ? $stored['groups'] : [], 0, self::MAX_GROUPS) as $group) {
            if (!is_array($group) || !is_string($group['label'] ?? null)) {
                continue;
            }
            $ids = self::ids($group['ids'] ?? [], array_diff_key($byId, $seen));
            $seen += array_fill_keys($ids, true);
            $groups[] = ['label' => mb_substr(trim($group['label']), 0, 80), 'ids' => $ids];
        }

        return [
            'links' => $links,
            'groups' => $groups,
            'home_categories' => self::ids($stored['home_categories'] ?? [], $byId),
        ];
    }

    public static function links(mixed $stored, array $categories): array
    {
        $menu = self::editorState($stored, $categories);
        $byId = array_column($categories, null, 'id');
        $resolve = static fn (array $ids): array => array_map(static fn (int $id): array => $byId[$id], $ids);
        return [
            'links' => $resolve($menu['links']),
            'groups' => array_map(
                static fn (array $group): array => ['label' => $group['label'], 'links' => $resolve($group['ids'])],
                $menu['groups']
            ),
        ];
    }

    public static function homeFilterSql(string $template, mixed $stored): string
    {
        $ids = $template === 'album-grid' && is_array($stored) ? self::ids($stored['home_categories'] ?? []) : [];
        if ($ids === []) {
            return '';
        }

        // Both the primary category and additional category assignments count.
        $idList = implode(',', $ids);
        return " AND (a.category_id IN ({$idList}) OR EXISTS (
            SELECT 1 FROM album_category ac WHERE ac.album_id = a.id AND ac.category_id IN ({$idList})
        ))";
    }

    private static function ids(mixed $input, ?array $allowed = null): array
    {
        if (!is_array($input)) {
            return [];
        }

        $unique = [];
        foreach ($input as $id) {
            if (is_int($id) && $id > 0 && ($allowed === null || isset($allowed[$id]))) {
                $unique[$id] = $id;
            }
        }
        return array_values($unique);
    }
}
