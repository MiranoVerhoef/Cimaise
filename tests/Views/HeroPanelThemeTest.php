<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The album "hero" page template must follow the active theme.
 *
 * Regression: the readability veil over the hero image was a hard-coded white
 * gradient and the panel forced dark text, while the pills inside it switched
 * to their dark styles — dark pills with dark text on a white panel in the
 * dark theme. The veil now uses the theme surface colour.
 */
final class HeroPanelThemeTest extends TestCase
{
    public function testHeroVeilFollowsTheThemeSurface(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/frontend/gallery_hero.twig');

        $this->assertStringContainsString('var(--color-bg-secondary-rgb', $twig, 'hero veil must use the theme surface colour');
        $this->assertStringNotContainsString('rgba(255,255,255,0.86)', $twig, 'hero veil must not be a fixed white gradient');
        $this->assertStringNotContainsString('preserve-light-text', $twig, 'hero panel must not force light-theme text');
    }

    public function testThemeSurfaceVariableIsDefinedForBothThemes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/app.css');

        $this->assertSame(2, preg_match_all('/--color-bg-secondary-rgb:\s*\d+ \d+ \d+;/', $css), 'expected one light and one dark value');
    }
}
