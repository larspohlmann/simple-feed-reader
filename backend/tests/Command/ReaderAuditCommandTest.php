<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Exception\MalformedOptionException;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionResult;
use App\Service\ReaderAudit\DatabaseValue;
use App\Service\ReaderAudit\Exception\UnwritableFindingsFileException;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeArticleExtractor;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReaderAuditCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:reader:audit'));
    }

    private function subscribedEntry(string $email): Entry
    {
        $feed = new Feed('https://cli.example.com/feed-' . uniqid('', true));
        $this->em->persist($feed);
        $user = new User($email, new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable()));
        $entry = new Entry(
            $feed,
            'guid-' . uniqid('', true),
            'https://cli.example.com/article',
            'An article',
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('-1 hour'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** @return list<Entry> */
    private function subscribedEntries(string $email, int $count): array
    {
        $feed = new Feed('https://cli.example.com/feed-' . uniqid('', true));
        $this->em->persist($feed);
        $user = new User($email, new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable()));

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
            $this->em->persist($entry);
            $entries[] = $entry;
        }
        $this->em->flush();

        return $entries;
    }

    public function testAMalformedLimitExitsBeforeAnyFetch(): void
    {
        $user = new User('audit-malformed@example.com', new \DateTimeImmutable());
        $this->em->persist($user);
        $this->em->flush();

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
        $entry = $this->subscribedEntry('audit-widest@example.com');

        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok(
            'https://cli.example.com/article',
            'An article',
            null,
            null,
            '<p>Body.</p>',
            null,
        ));
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = sys_get_temp_dir() . '/reader-audit-widest-' . uniqid('', true) . '.jsonl';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--entries' => (string) $entry->requireId(),
            '--shard' => ' ',
            '--shards' => ' ',
            '--out' => $outPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertContains('https://cli.example.com/article', $extractor->calls);

        unlink($outPath);
    }

    public function testABlankShardWithTwoShardsSelectsHalfNotNone(): void
    {
        $entries = $this->subscribedEntries('audit-shard@example.com', 2);

        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok(
            'https://cli.example.com/article-0',
            'An article',
            null,
            null,
            '<p>Body.</p>',
            null,
        ));
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = sys_get_temp_dir() . '/reader-audit-shard-' . uniqid('', true) . '.jsonl';
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

        unlink($outPath);
    }

    public function testAnExplicitBeforeCutoffExcludesLaterEntries(): void
    {
        $this->subscribedEntry('audit-before@example.com');

        $extractor = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = sys_get_temp_dir() . '/reader-audit-before-' . uniqid('', true) . '.jsonl';

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

        unlink($outPath);
    }

    public function testABlankPerFeedOptionFallsBackToZeroRatherThanSamplingOne(): void
    {
        $this->subscribedEntry('audit-per-feed@example.com');

        $extractor = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = sys_get_temp_dir() . '/reader-audit-per-feed-' . uniqid('', true) . '.jsonl';

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--user' => 'audit-per-feed@example.com',
            '--per-feed' => ' ',
            '--out' => $outPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $extractor->calls, 'A per-feed cap of zero draws nothing, not one article per feed.');

        unlink($outPath);
    }

    public function testABlankOutOptionIsReportedAsUnwritableInsteadOfATypeError(): void
    {
        $entry = $this->subscribedEntry('audit-blank-out@example.com');

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
        $entry = $this->subscribedEntry('audit-blank-base@example.com');

        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok(
            'https://cli.example.com/article',
            'An article',
            null,
            null,
            '<p>Body.</p>',
            null,
        ));
        self::getContainer()->set(ArticleExtractorInterface::class, $extractor);

        $outPath = sys_get_temp_dir() . '/reader-audit-blank-base-' . uniqid('', true) . '.jsonl';

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

        unlink($outPath);
    }
}
