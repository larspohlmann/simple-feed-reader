<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Exception\MalformedOptionException;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\ReaderAudit\Exception\UnwritableFindingsFileException;
use App\Service\ReaderAudit\Support\DatabaseValue;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeArticleExtractor;
use App\Tests\Support\SeedsUsers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReaderAuditCommandTest extends DbTestCase
{
    use SeedsUsers;

    /** @var list<string> */
    private array $filesToDelete = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $path) {
            @unlink($path);
        }
    }

    public function testAMalformedLimitExitsBeforeAnyFetch(): void
    {
        $this->user('audit-malformed@example.com');

        $extractor = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $tester = $this->tester();

        try {
            $tester->execute(['--user' => 'audit-malformed@example.com', '--limit' => 'abc']);
            self::fail('A malformed --limit must be refused, not passed through to the sampler.');
        } catch (MalformedOptionException) {
            self::assertSame([], $extractor->calls);
        }
    }

    public function testAnAbsentUserFallsBackToTheWidestSubscriberAndBlankShardOptionsFallBackToZero(): void
    {
        $entry = $this->subscribedEntries('audit-widest@example.com', 1)[0];

        $extractor = $this->extractorReturningOk((string) $entry->getUrl());
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = $this->outputPath('widest');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--entries' => (string) $entry->requireId(),
            '--shard' => ' ',
            '--shards' => ' ',
            '--out' => $outPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertContains($entry->getUrl(), $extractor->calls);
    }

    public function testABlankShardWithTwoShardsSelectsHalfNotNone(): void
    {
        $entries = $this->subscribedEntries('audit-shard@example.com', 2);

        $extractor = $this->extractorReturningOk('https://cli.example.com/article-0');
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = $this->outputPath('shard');
        $entryIds = array_map(static fn (Entry $entry): string => (string) $entry->requireId(), $entries);

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--entries' => implode(',', $entryIds),
            '--shards' => '2',
            '--shard' => ' ',
            '--out' => $outPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(
            1,
            $extractor->calls,
            'A blank --shard must fall back to index 0, which keeps one of every two, not none.',
        );
    }

    public function testTheSummaryCountsTheSampleItsDistinctFeedsAndTheShard(): void
    {
        $entries = $this->subscribedEntries('audit-summary@example.com', 2);
        self::getContainer()->set(
            ArticleExtractorInterface::class,
            $this->extractorReturningOk('https://cli.example.com/article-0'),
        );
        $entryIds = array_map(static fn (Entry $entry): string => (string) $entry->requireId(), $entries);

        $tester = $this->tester();
        $tester->execute([
            '--entries' => implode(',', $entryIds),
            '--shards' => '2',
            '--shard' => '0',
            '--out' => $this->outputPath('summary'),
        ]);

        self::assertMatchesRegularExpression(
            '/user \d+ — 2 articles sampled over 1 feeds, 1 in this shard/',
            $tester->getDisplay(),
        );
    }

    public function testAnExplicitBeforeCutoffExcludesLaterEntries(): void
    {
        $this->subscribedEntries('audit-before@example.com', 1);

        $extractor = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = $this->outputPath('before');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'audit-before@example.com',
            '--before' => '2020-01-01',
            '--out' => $outPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [],
            $extractor->calls,
            'The entry was created after 2020-01-01, so an honoured cutoff excludes it.',
        );
    }

    public function testABlankPerFeedOptionFallsBackToZeroRatherThanSamplingOne(): void
    {
        $this->subscribedEntries('audit-per-feed@example.com', 1);

        $extractor = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = $this->outputPath('per-feed');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'audit-per-feed@example.com',
            '--per-feed' => ' ',
            '--out' => $outPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $extractor->calls, 'A per-feed cap of zero draws nothing, not one article per feed.');
    }

    public function testABlankOutOptionIsReportedAsUnwritableInsteadOfATypeError(): void
    {
        $entry = $this->subscribedEntries('audit-blank-out@example.com', 1)[0];

        $extractor = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $tester = $this->tester();

        try {
            $tester->execute([
                '--user' => 'audit-blank-out@example.com',
                '--entries' => (string) $entry->requireId(),
                '--out' => ' ',
            ]);
            self::fail('A blank --out must be reported as unwritable, not passed as null.');
        } catch (UnwritableFindingsFileException $refusal) {
            self::assertStringContainsString('Cannot write', $refusal->getMessage());
        }
    }

    public function testABlankBaseUrlFallsBackToAnEmptyOrigin(): void
    {
        $entry = $this->subscribedEntries('audit-blank-base@example.com', 1)[0];

        $extractor = $this->extractorReturningOk((string) $entry->getUrl());
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = $this->outputPath('blank-base');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'audit-blank-base@example.com',
            '--entries' => (string) $entry->requireId(),
            '--out' => $outPath,
            '--base-url' => ' ',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $line = trim(file_get_contents($outPath) ?: '');
        self::assertNotSame('', $line);
        /** @var array<string, mixed> $row */
        $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('/?subscription=', DatabaseValue::string($row['readerLink']));
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:reader:audit'));
    }

    /** @return list<Entry> */
    private function subscribedEntries(string $email, int $count): array
    {
        $feed = new Feed('https://cli.example.com/feed-' . uniqid('', true));
        $this->entityManager->persist($feed);
        $user = $this->user($email);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable()));

        $entries = [];
        for ($i = 0; $i < $count; ++$i) {
            $entry = new Entry(
                $feed,
                'guid-' . uniqid('', true),
                'https://cli.example.com/article-' . $i,
                'An article',
                new \DateTimeImmutable('-1 hour'),
                new \DateTimeImmutable('-1 hour'),
            );
            $this->entityManager->persist($entry);
            $entries[] = $entry;
        }
        $this->entityManager->flush();

        return $entries;
    }

    private function extractorReturningOk(string $url): FakeArticleExtractor
    {
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResultModel::ok($url, 'An article', null, null, '<p>Body.</p>', null));

        return $extractor;
    }

    private function outputPath(string $label): string
    {
        $path = sys_get_temp_dir() . '/reader-audit-' . $label . '-' . uniqid('', true) . '.jsonl';
        $this->filesToDelete[] = $path;

        return $path;
    }
}
