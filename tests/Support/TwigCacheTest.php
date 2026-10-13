<?php

declare(strict_types=1);

use App\Support\TwigCache;
use PHPUnit\Framework\TestCase;

final class TwigCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cimaise-twig-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/cache/twig', 0777, true);
        file_put_contents($this->root . '/version.json', '{"version":"1.0.0"}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testEachReleaseCompilesIntoItsOwnDirectoryAndDropsOlderOnes(): void
    {
        $twig = $this->root . '/storage/cache/twig';
        // Layout left behind by releases that compiled straight into the root.
        mkdir($twig . '/ab');
        file_put_contents($twig . '/ab/abcdef.php', 'legacy');

        $first = TwigCache::directory($this->root);
        self::assertNotNull($first);
        self::assertDirectoryExists($first);
        self::assertFileDoesNotExist($twig . '/ab/abcdef.php');
        mkdir($first . '/cd');
        file_put_contents($first . '/cd/compiled.php', 'first release');

        // Same release: nothing is discarded.
        self::assertSame($first, TwigCache::directory($this->root));
        self::assertFileExists($first . '/cd/compiled.php');

        file_put_contents($this->root . '/version.json', '{"version":"1.0.1"}');
        $second = TwigCache::directory($this->root);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        self::assertDirectoryDoesNotExist($first);
        self::assertSame([basename($second)], array_values(array_diff((array) scandir($twig), ['.', '..'])));
    }

    public function testPruningNeverFollowsALinkOutOfTheCache(): void
    {
        $outside = $this->root . '/keep';
        mkdir($outside);
        file_put_contents($outside . '/precious.txt', 'x');
        if (!@symlink($outside, $this->root . '/storage/cache/twig/link')) {
            self::markTestSkipped('symlinks unavailable');
        }
        self::assertNotNull(TwigCache::directory($this->root));
        self::assertFileExists($outside . '/precious.txt');
        self::assertFalse(is_link($this->root . '/storage/cache/twig/link'));
    }
}
