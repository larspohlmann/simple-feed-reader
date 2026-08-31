<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReaderAudit\AuditSample;
use App\Service\ReaderAudit\AuditSampler;
use App\Service\ReaderAudit\AuditUserResolver;
use App\Service\ReaderAudit\ReaderAuditRunner;
use App\Service\ReaderAudit\ReaderLink;
use App\Service\ReaderAudit\SampledEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the reader's extract-and-clean pipeline over a stratified sample of the
 * articles a user is subscribed to, and writes one JSON line per article with
 * the markers that say the cleaning probably went wrong. `app:reader:audit:report`
 * turns those lines into the ranked, clickable list.
 *
 * Split into sweep and report on purpose: the sweep is a thousand outbound page
 * fetches and takes minutes, the report is instant and gets re-run every time a
 * threshold or a phrase is questioned. The sweep also shards — the same seed
 * draws the same sample in every shard, so `--shards=8 --shard=0..7` in parallel
 * covers the sample once with no coordination.
 *
 * Never a CI gate: publisher outages, bot walls and rate limits make the result
 * a survey, not a verdict. Same reasoning as app:catalog:check-urls.
 */
#[AsCommand(
    name: 'app:reader:audit',
    description: 'Sweep subscribed articles through the reader pipeline and record bad-cleanup markers',
)]
final class ReaderAuditCommand extends Command
{
    private const int DEFAULT_LIMIT = 1000;
    private const int DEFAULT_PER_FEED = 8;
    private const int DEFAULT_SEED = 20260831;
    private const string DEFAULT_BASE_URL = 'http://localhost:4200';

    public function __construct(
        private readonly AuditUserResolver $users,
        private readonly AuditSampler $sampler,
        private readonly ReaderAuditRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $required = InputOption::VALUE_REQUIRED;

        $this
            ->addOption('user', null, $required, 'Account id or email; defaults to the widest subscriber')
            ->addOption('limit', null, $required, 'Articles to audit in total', (string) self::DEFAULT_LIMIT)
            ->addOption('per-feed', null, $required, 'Cap per feed', (string) self::DEFAULT_PER_FEED)
            ->addOption('seed', null, $required, 'Sample seed; shards share it', (string) self::DEFAULT_SEED)
            ->addOption('before', null, $required, 'Sample entries stored before this instant; shards share it')
            ->addOption('entries', null, $required, 'Audit these entry ids instead of drawing a sample')
            ->addOption('shards', null, $required, 'Split the sample into this many runs', '1')
            ->addOption('shard', null, $required, 'Which shard this process runs, from 0', '0')
            ->addOption('base-url', null, $required, 'SPA origin the report links to', self::DEFAULT_BASE_URL)
            ->addOption('out', null, $required, 'JSONL file to write', 'var/reader-audit/findings.jsonl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userId = $this->users->resolve($this->option($input, 'user'));
        $sample = $this->articlesToAudit($input, $userId);
        $mine = $this->shardOf($sample, $this->number($input, 'shard'), $this->number($input, 'shards'));

        $io->text(\sprintf(
            'user %d — %d articles sampled over %d feeds, %d in this shard',
            $userId,
            \count($sample),
            \count(array_unique(array_map(static fn (SampledEntry $e): int => $e->feedId, $sample))),
            \count($mine),
        ));

        $handle = $this->openOutput((string) $this->option($input, 'out'));
        $link = new ReaderLink((string) $this->option($input, 'base-url'));

        $io->progressStart(\count($mine));
        $flagged = 0;
        foreach ($this->runner->run($mine, $link) as $finding) {
            fwrite($handle, json_encode($finding->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
            $flagged += $finding->markers === [] ? 0 : 1;
            $io->progressAdvance();
        }
        $io->progressFinish();
        fclose($handle);

        $io->success(\sprintf('%d of %d audited articles carry at least one marker.', $flagged, \count($mine)));

        return Command::SUCCESS;
    }

    /** @return list<SampledEntry> */
    private function articlesToAudit(InputInterface $input, int $userId): array
    {
        $named = $this->option($input, 'entries');
        if ($named !== null) {
            return $this->sampler->pick(array_map(intval(...), explode(',', $named)), $userId);
        }

        return $this->sampler->sample(new AuditSample(
            $userId,
            $this->number($input, 'limit'),
            $this->number($input, 'per-feed'),
            $this->number($input, 'seed'),
            new \DateTimeImmutable($this->option($input, 'before') ?? 'now'),
        ));
    }

    /**
     * @param list<SampledEntry> $sample
     *
     * @return list<SampledEntry>
     */
    private function shardOf(array $sample, int $shard, int $shards): array
    {
        if ($shards <= 1) {
            return $sample;
        }

        $mine = [];
        foreach ($sample as $index => $entry) {
            if ($index % $shards === $shard) {
                $mine[] = $entry;
            }
        }

        return $mine;
    }

    /** @return resource */
    private function openOutput(string $path)
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot write %s.', $path));
        }

        return $handle;
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function number(InputInterface $input, string $name): int
    {
        return (int) ($this->option($input, $name) ?? '0');
    }
}
