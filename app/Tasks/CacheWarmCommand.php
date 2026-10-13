<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Services\CacheWarmService;
use App\Support\Database;
use App\Support\QueryCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command for pre-generating page caches.
 *
 * Generates JSON caches for the home and galleries pages to improve load
 * times for visitors. Album pages are cached on their first visit.
 *
 * Recommended cron setup (every 6 hours or after content updates):
 *   0 0,6,12,18 * * * cd /path/to/cimaise && php bin/console cache:warm --quiet-mode
 *
 * Or run manually after publishing new albums:
 *   php bin/console cache:warm
 */
#[AsCommand(name: 'cache:warm')]
class CacheWarmCommand extends Command
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Pre-generate page caches for faster load times')
             ->addOption('clear', 'c', InputOption::VALUE_NONE, 'Clear all caches before warming')
             ->addOption('home', null, InputOption::VALUE_NONE, 'Only warm home page cache')
             ->addOption('galleries', null, InputOption::VALUE_NONE, 'Only warm galleries page cache')
             ->addOption('albums', null, InputOption::VALUE_NONE, 'Accepted for compatibility: album pages are cached on their first visit, not pre-built')
             ->addOption('quiet-mode', null, InputOption::VALUE_NONE, 'Suppress all output (for cron)')
             ->setHelp(
                 <<<'HELP'
The <info>cache:warm</info> command pre-generates JSON caches for the listing pages:

  <info>php bin/console cache:warm</info>

This command caches:
  - Home page (with all images and srcsets)
  - Galleries listing page

Album pages are not pre-built: each public album is cached on its first
anonymous visit and rebuilt automatically after an edit.

Options:
  <info>--clear, -c</info>      Clear existing caches before warming
  <info>--home</info>           Only warm home page cache
  <info>--galleries</info>      Only warm galleries page cache
  <info>--albums</info>         Accepted for compatibility (album pages need no pre-build)

For cron usage, add the --quiet-mode flag:

  <info>0 */6 * * * cd /path/to/cimaise && php bin/console cache:warm --quiet-mode</info>

Run after publishing new albums or changing settings:

  <info>php bin/console cache:warm --clear</info>
HELP
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quiet = $input->getOption('quiet-mode');
        $clear = $input->getOption('clear');
        $onlyHome = $input->getOption('home');
        $onlyGalleries = $input->getOption('galleries');
        $onlyAlbums = $input->getOption('albums');

        // If no specific option, warm all
        $warmAll = !$onlyHome && !$onlyGalleries && !$onlyAlbums;

        if (!$quiet) {
            $output->writeln('<info>Cache Warming</info>');
            $output->writeln('');
        }

        try {
            $cacheService = new CacheWarmService($this->db);

            // Clear caches if requested
            if ($clear) {
                if (!$quiet) {
                    $output->writeln('Clearing existing caches...');
                }
                $cacheService->getPageCacheService()->clearAll();
                QueryCache::getInstance()->flush();
                if (!$quiet) {
                    $output->writeln('<comment>Caches cleared.</comment>');
                    $output->writeln('');
                }
            }

            $stats = [
                'home' => false,
                'galleries' => false,
                'errors' => [],
            ];

            // Warm home page
            if ($warmAll || $onlyHome) {
                if (!$quiet) {
                    $output->write('Building home page cache... ');
                }
                try {
                    $stats['home'] = $cacheService->buildHomeCache();
                    if (!$quiet) {
                        $output->writeln($stats['home'] ? '<info>OK</info>' : '<comment>SKIP</comment>');
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = 'Home: ' . $e->getMessage();
                    if (!$quiet) {
                        $output->writeln('<error>FAIL</error>');
                        $output->writeln('  <error>' . $e->getMessage() . '</error>');
                    }
                }
            }

            // Warm galleries page
            if ($warmAll || $onlyGalleries) {
                if (!$quiet) {
                    $output->write('Building galleries page cache... ');
                }
                try {
                    $stats['galleries'] = $cacheService->buildGalleriesCache();
                    if (!$quiet) {
                        $output->writeln($stats['galleries'] ? '<info>OK</info>' : '<comment>SKIP</comment>');
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = 'Galleries: ' . $e->getMessage();
                    if (!$quiet) {
                        $output->writeln('<error>FAIL</error>');
                        $output->writeln('  <error>' . $e->getMessage() . '</error>');
                    }
                }
            }

            // Album pages cannot be built faithfully outside the request that
            // renders them, so they are cached on their first anonymous visit.
            if (($warmAll || $onlyAlbums) && !$quiet) {
                $output->writeln('<comment>Album pages: cached on first visit (nothing to pre-build).</comment>');
            }

            // Summary
            if (!$quiet) {
                $output->writeln('');
                $output->writeln('<info>Cache warming complete!</info>');
                $output->writeln('');

                $totalCached = ($stats['home'] ? 1 : 0) + ($stats['galleries'] ? 1 : 0);
                $output->writeln("Summary: {$totalCached} pages cached");

                if (!empty($stats['errors'])) {
                    $output->writeln('');
                    $output->writeln('<comment>Warnings:</comment>');
                    foreach ($stats['errors'] as $error) {
                        $output->writeln("  - {$error}");
                    }
                }
            }

            return empty($stats['errors']) ? Command::SUCCESS : Command::FAILURE;

        } catch (\Throwable $e) {
            if (!$quiet) {
                $output->writeln('');
                $output->writeln('<error>Cache warming failed: ' . $e->getMessage() . '</error>');
            }
            return Command::FAILURE;
        }
    }
}
