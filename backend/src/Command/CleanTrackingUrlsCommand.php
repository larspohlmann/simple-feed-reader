<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use App\Service\Operations\UrlCleanup\TrackingUrlCleanup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:urls:clean-tracking',
    description: 'Remove tracking parameters from stored entry and link-card URLs and recompute their hashes',
)]
final class CleanTrackingUrlsCommand extends Command
{
    public function __construct(private readonly TrackingUrlCleanup $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('dry-run') === true) {
            $io->success('Would clean: ' . self::summary($this->cleanup->count()));

            return Command::SUCCESS;
        }

        $io->success(self::summary($this->cleanup->clean()));

        return Command::SUCCESS;
    }

    private static function summary(TrackingUrlCleanupReportModel $report): string
    {
        return \sprintf(
            '%d entries cleaned, %d hashes changed, %d cards cleaned',
            $report->entriesCleaned,
            $report->hashesChanged,
            $report->cardsCleaned,
        );
    }
}
