<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\ReaderAudit\AuditSample;
use App\Service\ReaderAudit\AuditSampler;
use App\Service\ReaderAudit\SampledEntry;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Connection;

final class AuditSamplerTest extends DbTestCase
{
    private const string MOMENT = '2026-07-01T00:00:00Z';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('audit-sampler@example.com', new \DateTimeImmutable(self::MOMENT));
        $this->em->persist($this->user);
    }

    public function testEverySubscribedFeedIsRepresentedEvenWhenOneOfThemPublishesTenTimesAsOften(): void
    {
        // The point of the stratification: a plain random draw over all entries
        // would spend the whole budget on the loudest feed, which is exactly the
        // feed whose cleaners are already known to work.
        $this->feedWithEntries('loud', 40);
        $this->feedWithEntries('quiet', 1);
        $this->em->flush();

        $sample = $this->drawn(limit: 10, perFeed: 10, seed: 1);

        self::assertSame(['loud', 'quiet'], $this->feedTitlesOf($sample));
    }

    public function testTheFirstArticleOfEveryFeedIsDrawnBeforeAnyFeedGetsASecond(): void
    {
        $this->feedWithEntries('a', 5);
        $this->feedWithEntries('b', 5);
        $this->em->flush();

        $sample = $this->drawn(limit: 2, perFeed: 5, seed: 1);

        self::assertSame(['a', 'b'], $this->feedTitlesOf($sample));
    }

    public function testTheSameSeedDrawsTheSameSampleSoParallelShardsAgreeWithoutTalking(): void
    {
        $this->feedWithEntries('a', 20);
        $this->em->flush();

        $first = $this->drawn(limit: 5, perFeed: 5, seed: 99);
        $second = $this->drawn(limit: 5, perFeed: 5, seed: 99);

        self::assertSame($this->entryIdsOf($first), $this->entryIdsOf($second));
    }

    public function testAnEntryWithoutASourceUrlIsNeverSampledBecauseTheReaderCannotFetchIt(): void
    {
        $feed = $this->feedWithEntries('urlless', 0);
        $moment = new \DateTimeImmutable(self::MOMENT);
        $this->em->persist(new Entry($feed, 'no-url', null, 'Ohne URL', $moment, $moment));
        $this->em->flush();

        self::assertSame([], $this->drawn(limit: 10, perFeed: 10, seed: 1));
    }

    public function testAFeedThePublisherNeverTitledIsNamedByItsUrlRatherThanDropped(): void
    {
        $feed = new Feed('https://untitled.example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable(self::MOMENT)));
        $moment = new \DateTimeImmutable(self::MOMENT);
        $this->em->persist(new Entry($feed, 'g', 'https://untitled.example.com/g', 'T', $moment, $moment));
        $this->em->flush();

        $sample = $this->drawn(limit: 10, perFeed: 10, seed: 1);

        self::assertSame(['https://untitled.example.com/feed.xml'], $this->feedTitlesOf($sample));
    }

    public function testAnotherAccountsSubscriptionsAreNotAudited(): void
    {
        $stranger = new User('audit-stranger@example.com', new \DateTimeImmutable(self::MOMENT));
        $this->em->persist($stranger);
        $strangerFeed = new Feed('https://stranger.example.com/feed.xml');
        $strangerFeed->setTitle('stranger');
        $this->em->persist($strangerFeed);
        $this->em->persist(new Subscription($stranger, $strangerFeed, new \DateTimeImmutable(self::MOMENT)));
        $moment = new \DateTimeImmutable(self::MOMENT);
        $this->em->persist(new Entry($strangerFeed, 'g', 'https://stranger.example.com/g', 'T', $moment, $moment));
        $this->feedWithEntries('mine', 1);
        $this->em->flush();

        $sample = $this->drawn(limit: 10, perFeed: 10, seed: 1);

        self::assertSame(['mine'], $this->feedTitlesOf($sample));
    }

    private function feedWithEntries(string $title, int $entryCount): Feed
    {
        $feed = new Feed('https://' . $title . '.example.com/feed.xml');
        $feed->setTitle($title);
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable(self::MOMENT)));

        for ($index = 0; $index < $entryCount; $index++) {
            $this->em->persist(new Entry(
                $feed,
                $title . '-' . $index,
                'https://' . $title . '.example.com/' . $index,
                'Titel ' . $index,
                new \DateTimeImmutable(self::MOMENT),
                new \DateTimeImmutable(self::MOMENT),
            ));
        }

        return $feed;
    }

    /**
     * @param list<SampledEntry> $sample
     *
     * @return list<string>
     */
    private function feedTitlesOf(array $sample): array
    {
        $titles = array_values(array_unique(array_map(static fn (SampledEntry $e): string => $e->feedTitle, $sample)));
        sort($titles);

        return $titles;
    }

    /**
     * @param list<SampledEntry> $sample
     *
     * @return list<int>
     */
    private function entryIdsOf(array $sample): array
    {
        return array_map(static fn (SampledEntry $e): int => $e->entryId, $sample);
    }

    private function userId(): int
    {
        return (int) $this->user->getId();
    }

    public function testAnEntryStoredAfterTheCutoffIsNotDrawnSoEveryShardSeesTheSameSet(): void
    {
        // The refresh worker ingests during a sweep. Without the cutoff, a shard
        // that started a minute later would reshuffle a larger candidate set and
        // audit different articles than its siblings.
        $feed = $this->feedWithEntries('late', 0);
        $stored = new \DateTimeImmutable('2026-07-05T00:00:00Z');
        $this->em->persist(new Entry($feed, 'late', 'https://late.example.com/1', 'T', $stored, $stored));
        $this->em->flush();

        $sample = $this->drawn(limit: 10, perFeed: 10, seed: 1, before: '2026-07-02T00:00:00Z');

        self::assertSame([], $sample);
    }

    public function testPickAuditsTheNamedArticlesWithoutDrawingAtAll(): void
    {
        $this->feedWithEntries('a', 3);
        $this->em->flush();
        $drawn = $this->drawn(limit: 10, perFeed: 10, seed: 1);
        $wanted = $drawn[2]->entryId;

        $picked = $this->sampler()->pick([$wanted], $this->userId());

        self::assertSame([$wanted], $this->entryIdsOf($picked));
    }

    /** @return list<SampledEntry> */
    private function drawn(int $limit, int $perFeed, int $seed, string $before = '2030-01-01T00:00:00Z'): array
    {
        return $this->sampler()->sample(
            new AuditSample($this->userId(), $limit, $perFeed, $seed, new \DateTimeImmutable($before)),
        );
    }

    private function sampler(): AuditSampler
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return new AuditSampler($connection);
    }
}
