<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReaderAudit\AuditSampler;
use App\Service\ReaderAudit\AuditUserResolver;
use App\Service\ReaderAudit\Model\AuditSampleModel;
use App\Service\ReaderAudit\Model\AuditShardModel;
use App\Service\ReaderAudit\Model\ReaderLinkModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;
use App\Service\ReaderAudit\Pass\AuditFindingsFile;
use App\Service\ReaderAudit\ReaderAuditRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes one JSON line of suspicion markers per article in a stratified sample of a user's articles, for
 * app:reader:audit:report. Every shard draws the same sample from the seed, so `--shards=8 --shard=0..7` cover it
 * once. A survey, never a CI gate.
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

        $userId = $this->users->resolve(ConsoleOption::text($input, 'user'));
        $sample = $this->articlesToAudit($input, $userId);
        $shard = new AuditShardModel($this->number($input, 'shard'), $this->number($input, 'shards'));
        $shardEntries = $shard->pick($sample);

        $io->text(\sprintf(
            'user %d — %d articles sampled over %d feeds, %d in this shard',
            $userId,
            \count($sample),
            \count(array_unique(array_map(
                static fn (SampledEntryModel $sampledEntry): int => $sampledEntry->feedId,
                $sample,
            ))),
            \count($shardEntries),
        ));

        $file = AuditFindingsFile::create((string) ConsoleOption::text($input, 'out'));
        $link = new ReaderLinkModel((string) ConsoleOption::text($input, 'base-url'));

        $io->progressStart(\count($shardEntries));
        $flagged = 0;
        foreach ($this->runner->run($shardEntries, $link) as $finding) {
            $file->append($finding);
            $flagged += $finding->markers === [] ? 0 : 1;
            $io->progressAdvance();
        }
        $io->progressFinish();
        $file->close();

        $io->success(\sprintf('%d of %d audited articles carry at least one marker.', $flagged, \count($shardEntries)));

        return Command::SUCCESS;
    }

    /** @return list<SampledEntryModel> */
    private function articlesToAudit(InputInterface $input, int $userId): array
    {
        $named = ConsoleOption::text($input, 'entries');
        if ($named !== null) {
            return $this->sampler->pick(array_map(intval(...), explode(',', $named)), $userId);
        }

        return $this->sampler->sample(new AuditSampleModel(
            $userId,
            $this->number($input, 'limit'),
            $this->number($input, 'per-feed'),
            $this->number($input, 'seed'),
            new \DateTimeImmutable(ConsoleOption::text($input, 'before') ?? 'now'),
        ));
    }

    private function number(InputInterface $input, string $name): int
    {
        return ConsoleOption::wholeNumber($input, $name) ?? 0;
    }
}
