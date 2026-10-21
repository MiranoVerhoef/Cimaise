<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/AppSandbox.php';

/**
 * Installs a site from scratch through the real web installer
 * (public/installer.php), in an isolated copy of the tree, then uses it.
 */
final class FreshInstallTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@install.test';
    private const ADMIN_PASSWORD = 'Install-Test-123!';

    private AppSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = AppSandbox::create();
        $this->sandbox->startServer();
    }

    protected function tearDown(): void
    {
        $this->sandbox->destroy();
    }

    public function testWebInstallerProducesAWorkingSite(): void
    {
        $this->sandbox->installThroughWizard(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'Lifecycle Test Site');

        // What the installer must leave on disk.
        self::assertFileExists($this->sandbox->root . '/database/database.sqlite');
        self::assertFileExists($this->sandbox->root . '/storage/tmp/.installed');
        $env = (string) file_get_contents($this->sandbox->root . '/.env');
        self::assertMatchesRegularExpression('/^DB_CONNECTION="?sqlite"?$/m', $env);
        self::assertMatchesRegularExpression('/^SESSION_SECRET="?[a-f0-9]{32,}"?$/m', $env);
        self::assertMatchesRegularExpression('/^APP_URL="?http/m', $env);

        // Every table of the shipped schema, one admin, the wizard's settings.
        $schema = (string) file_get_contents($this->sandbox->root . '/database/schema.sqlite.sql');
        preg_match_all('/^CREATE TABLE IF NOT EXISTS\s+[`"]?(\w+)/mi', $schema, $tables);
        $db = $this->sandbox->database();
        $existing = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], array_values(array_diff($tables[1], $existing)), 'tables missing after install');
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn());
        self::assertStringContainsString('Lifecycle Test Site', (string) $db->query("SELECT value FROM settings WHERE key = 'site.title'")->fetchColumn());
        // The newest schema column must be there on a fresh install too.
        self::assertContains('show_equipment', array_column($db->query('PRAGMA table_info(albums)')->fetchAll(PDO::FETCH_ASSOC), 'name'));

        // The installed site answers, and the installer is closed.
        $home = $this->sandbox->request('/');
        self::assertSame(200, $home['status'], substr($home['body'], 0, 500));
        self::assertStringContainsString('Lifecycle Test Site', $home['body']);
        self::assertStringNotContainsStringIgnoringCase('fatal error', $home['body']);
        self::assertSame(200, $this->sandbox->request('/galleries')['status']);
        self::assertNotSame(200, $this->sandbox->request('/installer.php?step=database')['status'], 'installer must lock itself after a completed install');

        // The admin created by the wizard can sign in and reach the dashboard.
        $this->sandbox->forgetCookies();
        self::assertSame(302, $this->sandbox->request('/admin')['status'], 'admin is gated');
        $login = $this->sandbox->request('/admin/login');
        self::assertSame(200, $login['status']);
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $login['body'], $match));
        $signedIn = $this->sandbox->request('/admin/login', ['csrf' => $match[1], 'email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(302, $signedIn['status']);
        self::assertStringEndsWith('/admin', rtrim($signedIn['headers']['location'] ?? '', '/'));
        $dashboard = $this->sandbox->request('/admin');
        self::assertSame(200, $dashboard['status'], substr($dashboard['body'], 0, 500));
        self::assertSame(200, $this->sandbox->request('/admin/settings')['status']);
        self::assertSame(200, $this->sandbox->request('/admin/updates')['status']);
    }
}
