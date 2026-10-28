<?php
declare(strict_types=1);

namespace App\Services;

final class AdminSettingsSections
{
    /** @var array<string, array{title: string, fields: list<string>, keys: list<string>}> */
    public const PAGES = [
        'images' => [
            'title' => 'admin.settings.image_processing',
            'fields' => ['fmt_avif', 'fmt_webp', 'fmt_jpg', 'fmt_jxl', 'q_avif', 'q_webp', 'q_jpg', 'q_jxl', 'preview_w', 'bp_sm', 'bp_md', 'bp_lg', 'bp_xl', 'bp_xxl', 'variants_async'],
            'keys' => ['image.formats', 'image.quality', 'image.preview', 'image.breakpoints', 'image.variants_async'],
        ],
    ];

    /** @return list<string> */
    public static function movedFields(): array
    {
        return array_merge(...array_column(self::PAGES, 'fields'));
    }

    /** @return list<string> */
    public static function movedKeys(): array
    {
        return array_merge(...array_column(self::PAGES, 'keys'));
    }
}
