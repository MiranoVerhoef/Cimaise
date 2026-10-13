<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MaintenancePageTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function render(array $config, string $basePath = ''): string
    {
        $config += ['title' => 'Back soon', 'message' => 'Working on it', 'site_title' => 'Site', 'show_logo' => true];
        ob_start();
        (static function () use ($config, $basePath): void {
            include dirname(__DIR__, 2) . '/plugins/maintenance-mode/templates/maintenance.php';
        })();
        return (string) ob_get_clean();
    }

    /** @runInSeparateProcess */
    public function testUploadedLogoKeepsItsStoredMediaPath(): void
    {
        // Uploaded logos are stored as '/media/<file>': prefixing /media/ again broke the image.
        $html = $this->render(['site_logo' => '/media/logo-abc.png'], '/portfolio');
        self::assertStringContainsString('src="/portfolio/media/logo-abc.png"', $html);
        self::assertStringNotContainsString('/media//media/', $html);
    }

    /** @runInSeparateProcess */
    public function testLegacyBareFilenameAndAbsoluteUrlStillWork(): void
    {
        self::assertStringContainsString('src="/media/logo.png"', $this->render(['site_logo' => 'logo.png']));
        self::assertStringContainsString('src="https://cdn.example/logo.png"', $this->render(['site_logo' => 'https://cdn.example/logo.png']));
    }

    /** @runInSeparateProcess */
    public function testAdminLoginLinkCanBeHidden(): void
    {
        self::assertStringContainsString('/admin/login', $this->render([]));
        self::assertStringNotContainsString('/admin/login', $this->render(['show_admin_login' => false]));
    }
}
