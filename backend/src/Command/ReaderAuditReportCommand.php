<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReaderAudit\AuditFindings;
use App\Service\ReaderAudit\AuditReportHtml;
use App\Service\ReaderAudit\CleanupMarker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns the sweep's JSONL files into the ranked, clickable report. Separate from
 * the sweep because it is instant and gets re-run every time a threshold or a
 * phrase is questioned — re-fetching a thousand pages to re-score them would be
 * the whole cost of the audit for none of its value.
 */
#[AsCommand(
    name: 'app:reader:audit:report',
    description: 'Rank the reader-audit findings and write the clickable HTML report',
)]
final class ReaderAuditReportCommand extends Command
{
    private const int TERMINAL_ROWS = 20;
    private const string DEFAULT_IN = 'var/reader-audit/findings*.jsonl';
    private const string DEFAULT_OUT = 'var/reader-audit/report.html';

    public function __construct(private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $required = InputOption::VALUE_REQUIRED;

        $this
            ->addOption('in', null, $required, 'Glob of the sweep JSONL files', self::DEFAULT_IN)
            ->addOption('out', null, $required, 'HTML report to write', self::DEFAULT_OUT)
            ->addOption('top', null, $required, 'Candidates to list in the report', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pattern = $this->option($input, 'in');
        $paths = glob($pattern) ?: [];
        if ($paths === []) {
            $io->error(\sprintf('No sweep files match %s — run app:reader:audit first.', $pattern));

            return Command::FAILURE;
        }

        $findings = AuditFindings::fromJsonlFiles($paths);
        $reportPath = $this->option($input, 'out');
        $report = new AuditReportHtml((int) $this->option($input, 'top'));
        file_put_contents($reportPath, $report->render($findings, $this->clock->now()->format('Y-m-d H:i')));

        $this->printSummary($io, $findings);
        $io->success(\sprintf('%s — open it in a browser, every title links into the reader.', $reportPath));

        return Command::SUCCESS;
    }

    private function printSummary(SymfonyStyle $io, AuditFindings $findings): void
    {
        $io->section(\sprintf(
            '%d articles over %d feeds, %d flagged',
            $findings->audited(),
            $findings->feedCount(),
            \count($findings->ranked()),
        ));

        $bySuspect = $findings->tally(static fn (CleanupMarker $m): string => $m->suspect);
        $byCode = $findings->tally(static fn (CleanupMarker $m): string => $m->code);
        $io->table(['stage to look at', 'articles'], $this->rowsOf($bySuspect));
        $io->table(['marker', 'articles'], $this->rowsOf($byCode));

        $worst = [];
        foreach (\array_slice($findings->ranked(), 0, self::TERMINAL_ROWS) as $finding) {
            $worst[] = [
                $finding->score(),
                $finding->feedTitle,
                mb_substr($finding->title, 0, 50),
                $finding->readerLink,
            ];
        }
        $io->table(['score', 'feed', 'article', 'open in the reader'], $worst);
    }

    private function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{string, int}>
     */
    private function rowsOf(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [$key, $count];
        }

        return $rows;
    }
}
