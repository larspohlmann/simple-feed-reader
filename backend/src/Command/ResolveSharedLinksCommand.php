<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Operations\LinkResolutionBackfill\Model\LinkResolutionBackfillReportModel;
use App\Service\Operations\LinkResolutionBackfill\SharedLinkResolutionBackfill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:shared-links:resolve',
    description: 'Queue and resolve every existing short-link shared link (one-off, #2236)',
)]
final class ResolveSharedLinksCommand extends Command
{
    public function __construct(private readonly SharedLinkResolutionBackfill $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be queued and follow nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('dry-run') === true) {
            $output->writeln(\sprintf('Would queue %d short links', $this->backfill->count()));

            return Command::SUCCESS;
        }

        $output->writeln(self::summary($this->backfill->run()));

        return Command::SUCCESS;
    }

    private static function summary(LinkResolutionBackfillReportModel $report): string
    {
        $resolutions = $report->resolutions;

        return \sprintf(
            'Queued %d; resolved %d, unchanged %d, unresolved %d, deferred %d in %.1f s (%.2f s a link)',
            $report->queued,
            $resolutions->resolved,
            $resolutions->unchanged,
            $resolutions->unresolved,
            $resolutions->deferred,
            $resolutions->seconds,
            $resolutions->seconds / max(1, $resolutions->considered),
        );
    }
}
