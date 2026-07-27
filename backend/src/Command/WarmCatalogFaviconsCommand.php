<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\CatalogFaviconWarmer;
use App\Service\Catalog\CatalogWarmReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warms every missing or stale catalog favicon, looping CatalogFaviconWarmer
 * until nothing is left (or, with --limit, one bounded slice).
 *
 * A convenience, not the mechanism: the admin UI drives the same warmer over
 * HTTP after an import, so an install that never runs a console command still
 * gets its icons. This exists for cron and for operators who prefer a shell.
 *
 * Self-limiting: minutes on the first run against an empty cache, a no-op after,
 * because cached rows match neither the missing nor the stale predicate.
 */
#[AsCommand(
    name: 'app:catalog:warm-favicons',
    description: 'Fetch and cache missing or stale catalog favicons',
)]
final class WarmCatalogFaviconsCommand extends Command
{
    private const int SLICE_BUDGET_SECONDS = 120;

    public function __construct(
        private readonly CatalogFaviconWarmer $warmer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-warm all rows, ignoring freshness windows');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Warm at most this many in one slice, then stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $this->limitOption($input);

        // --force resets freshness ONCE, up front, then warming proceeds through
        // the normal window so each row leaves the due set as it is re-warmed —
        // resetting per slice would re-mark just-warmed rows and never terminate.
        if ((bool) $input->getOption('force')) {
            $this->warmer->markAllForReWarming();
        }

        $totals = null !== $limit
            ? $this->warmOneSlice($io, $limit)
            : $this->warmUntilDone($io);

        $io->success(\sprintf('Catalog favicons: warmed %d, failed %d.', $totals->warmed, $totals->failed));

        // Always 0: an unreachable publisher is not an error condition.
        return Command::SUCCESS;
    }

    private function warmOneSlice(SymfonyStyle $io, int $limit): CatalogWarmReport
    {
        $report = $this->warmer->warm(self::SLICE_BUDGET_SECONDS, $limit);
        $this->report($io, $report->warmed, $report->failed, $report->remaining);

        return $report;
    }

    private function warmUntilDone(SymfonyStyle $io): CatalogWarmReport
    {
        $warmed = 0;
        $failed = 0;
        $previousRemaining = \PHP_INT_MAX;
        do {
            $report = $this->warmer->warm(self::SLICE_BUDGET_SECONDS);
            $warmed += $report->warmed;
            $failed += $report->failed;
            $this->report($io, $warmed, $failed, $report->remaining);

            // A slice that achieved nothing while claiming work remains would
            // spin forever — every candidate is failing, so stop and report it.
            if (0 === $report->warmed && 0 === $report->failed) {
                break;
            }

            // Belt-and-suspenders: a slice that does not shrink the backlog can
            // never converge, so stop even if a future window change regresses.
            if ($report->remaining >= $previousRemaining) {
                break;
            }
            $previousRemaining = $report->remaining;
        } while ($report->remaining > 0);

        return new CatalogWarmReport($warmed, $failed, $report->remaining);
    }

    private function report(SymfonyStyle $io, int $warmed, int $failed, int $remaining): void
    {
        $io->writeln(\sprintf('  %d warmed, %d failed, %d remaining', $warmed, $failed, $remaining));
    }

    private function limitOption(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if (!\is_string($value) || '' === trim($value) || !ctype_digit(trim($value))) {
            return null;
        }

        return max(1, (int) $value);
    }
}
