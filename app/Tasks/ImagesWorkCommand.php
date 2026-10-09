<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Services\ImageJobQueue;
use App\Support\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImagesWorkCommand extends Command
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct('images:work');
    }

    protected function configure(): void
    {
        $this->setDescription('Process pending image jobs; failed jobs remain queued for retry')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Retry until the queue is empty (up to five minutes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = new ImageJobQueue($this->db);
        if (!$input->getOption('watch')) {
            return $queue->drain() > 0 ? Command::FAILURE : Command::SUCCESS;
        }
        $dir = dirname(__DIR__, 2) . '/storage/image-jobs';
        if (!$queue->hasJobs()) {
            return Command::SUCCESS;
        }
        $lock = fopen($dir . '/watch.lock', 'c');
        if ($lock === false) {
            return Command::FAILURE;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return Command::SUCCESS;
            }
            $deadline = time() + 300;
            do {
                $queue->drain();
                if (!$queue->hasJobs()) {
                    return Command::SUCCESS;
                }
                sleep(2);
            } while (time() < $deadline);
            return Command::FAILURE;
        } finally {
            fclose($lock);
        }
    }
}
