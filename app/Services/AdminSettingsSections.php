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
        'display' => [
            'title' => 'admin.settings.display',
            'fields' => ['dark_mode', 'allow_theme_toggle', 'custom_css', 'pwa_theme_color', 'pwa_background_color'],
            'keys' => ['frontend.dark_mode', 'frontend.allow_theme_toggle', 'frontend.custom_css', 'pwa.theme_color', 'pwa.background_color'],
        ],
        'gallery' => [
            'title' => 'admin.settings.gallery_settings',
            'fields' => ['gallery_page_template', 'default_template_id', 'show_exif_lightbox', 'show_tags_in_header', 'pagination_limit'],
            'keys' => ['gallery.page_template', 'gallery.default_template_id', 'lightbox.show_exif', 'navigation.show_tags_in_header', 'pagination.limit'],
        ],
        'protection' => [
            'title' => 'admin.settings.protection',
            'fields' => ['nsfw_global_warning', 'recaptcha_site_key', 'recaptcha_secret_key', 'recaptcha_enabled', 'disable_right_click'],
            'keys' => ['privacy.nsfw_global_warning', 'recaptcha.site_key', 'recaptcha.secret_key', 'recaptcha.enabled', 'frontend.disable_right_click'],
        ],
        'performance' => [
            'title' => 'admin.settings.performance',
            'fields' => ['admin_debug_logs', 'compression_enabled', 'compression_type', 'compression_level', 'cache_enabled', 'static_cache_max_age', 'media_cache_max_age', 'html_cache_max_age'],
            'keys' => ['admin.debug_logs', 'performance.compression_enabled', 'performance.compression_type', 'performance.compression_level', 'performance.cache_enabled', 'performance.static_cache_max_age', 'performance.media_cache_max_age', 'performance.html_cache_max_age'],
        ],
        'maintenance' => [
            'title' => 'admin.maintenance.title',
            'fields' => ['maintenance_enabled', 'maintenance_title', 'maintenance_message', 'maintenance_show_logo', 'maintenance_show_admin_login', 'maintenance_show_countdown'],
            'keys' => ['maintenance.enabled', 'maintenance.title', 'maintenance.message', 'maintenance.show_logo', 'maintenance.show_admin_login', 'maintenance.show_countdown'],
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
