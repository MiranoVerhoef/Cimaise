<?php
declare(strict_types=1);

use App\Controllers\Admin\SettingsController;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class SettingsSectionsTest extends TestCase
{
    private Database $db;
    private mixed $cache;
    private array $session;

    protected function setUp(): void
    {
        $this->cache = (new ReflectionProperty(SettingsService::class, 'cache'))->getValue();
        $this->session = $_SESSION ?? [];
        $_SESSION = ['csrf' => 'sections-test'];
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $settings = new SettingsService($this->db);
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $settings->defaults());
        $settings->setMany([
            'site.title' => 'Preserved title', 'site.logo' => '/media/logo.png',
            'image.breakpoints' => ['sm' => 200, 'md' => 400, 'lg' => 800, 'xl' => 1200, 'xxl' => 1600],
            'image.variants_async' => true, 'maintenance.enabled' => true,
            'cache.storage_backend' => 'database',
        ]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->cache);
        $_SESSION = $this->session;
    }

    private function controller(): SettingsController
    {
        return new class($this->db, Twig::create(dirname(__DIR__, 2) . '/app/Views')) extends SettingsController {
            protected function ensureSession(): void {}
            protected function getBasePath(): string { return ''; }
        };
    }

    public function testImagePageOnlyWritesItsSettings(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings/images')->withParsedBody([
            'csrf' => 'sections-test', 'fmt_jpg' => '1', 'q_jpg' => '90',
            'site_title' => 'Ignore this', 'logo_type' => 'text',
        ]);
        $response = $this->controller()->section($request, new Response(), ['section' => 'images']);
        self::assertSame('/admin/settings/images?saved=1', $response->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertSame(90, $settings->get('image.quality')['jpg']);
        self::assertFalse($settings->get('image.variants_async'));
        self::assertSame('Preserved title', $settings->get('site.title'));
        self::assertSame('/media/logo.png', $settings->get('site.logo'));
        self::assertTrue($settings->get('maintenance.enabled'));
    }

    public function testGeneralFormCannotResetMovedSettings(): void
    {
        $before = (new SettingsService($this->db))->get('image.breakpoints');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings')->withParsedBody([
            'csrf' => 'sections-test', '_settings_page' => 'general', 'site_title' => 'New title',
            'bp_sm' => '9999', 'variants_async' => '',
        ]);
        $response = $this->controller()->save($request, new Response());
        self::assertSame('/admin/settings?saved=1', $response->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertSame('New title', $settings->get('site.title'));
        self::assertSame($before, $settings->get('image.breakpoints'));
        self::assertTrue($settings->get('image.variants_async'));
    }

    public function testDisplaySavePreservesOtherSections(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings/display')->withParsedBody([
            'csrf' => 'sections-test', 'dark_mode' => '1', 'allow_theme_toggle' => '1',
            'pwa_theme_color' => '#123456', 'site_title' => 'Ignore this', 'q_jpg' => '1',
        ]);
        $response = $this->controller()->section($request, new Response(), ['section' => 'display']);
        self::assertSame('/admin/settings/display?saved=1', $response->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertTrue($settings->get('frontend.dark_mode'));
        self::assertSame('#123456', $settings->get('pwa.theme_color'));
        self::assertSame('Preserved title', $settings->get('site.title'));
        self::assertTrue($settings->get('image.variants_async'));
        self::assertTrue($settings->get('maintenance.enabled'));
    }

    public function testGallerySavePreservesSiteAndImageSettings(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings/gallery')->withParsedBody([
            'csrf' => 'sections-test', 'gallery_page_template' => 'hero', 'pagination_limit' => '7',
            'site_title' => 'Ignore this', 'variants_async' => '',
        ]);
        $response = $this->controller()->section($request, new Response(), ['section' => 'gallery']);
        self::assertSame('/admin/settings/gallery?saved=1', $response->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertSame('hero', $settings->get('gallery.page_template'));
        self::assertSame(7, $settings->get('pagination.limit'));
        self::assertSame('Preserved title', $settings->get('site.title'));
        self::assertTrue($settings->get('image.variants_async'));
    }

    public function testProtectionSavePreservesOtherSections(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings/protection')->withParsedBody([
            'csrf' => 'sections-test', 'nsfw_global_warning' => '1', 'disable_right_click' => '1',
            'recaptcha_site_key' => 'test_site', 'recaptcha_secret_key' => 'test_secret', 'recaptcha_enabled' => '1',
            'site_title' => 'Ignore this',
        ]);
        $response = $this->controller()->section($request, new Response(), ['section' => 'protection']);
        self::assertSame('/admin/settings/protection?saved=1', $response->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertTrue($settings->get('privacy.nsfw_global_warning'));
        self::assertTrue($settings->get('recaptcha.enabled'));
        self::assertSame('test_secret', $settings->get('recaptcha.secret_key'));
        self::assertSame('Preserved title', $settings->get('site.title'));
        self::assertTrue($settings->get('image.variants_async'));
    }

    public function testUnknownSectionsAreNotAccepted(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings/unknown');
        self::assertSame(404, $this->controller()->section($request, new Response(), ['section' => 'unknown'])->getStatusCode());
    }
}
