<?php

declare(strict_types=1);

namespace App\Tasks;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'images:generate', description: 'Generate image variants as per settings')]
class ImagesGenerateCommand extends ImagesGenerateVariantsCommand
{
    protected function configure(): void
    {
        parent::configure();
        // Keep the historical CLI option. Both commands now share the safe,
        // protection-aware encoder and skip existing variants unless forced.
        $this->addOption('missing', null, InputOption::VALUE_NONE, 'Only generate missing variants');
    }
}
