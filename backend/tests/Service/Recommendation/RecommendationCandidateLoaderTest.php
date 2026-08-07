<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Recommendation\RecommendationCandidateLoader;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class RecommendationCandidateLoaderTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('candidates@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->subscription = new Subscription(
            $this->user,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($this->subscription);

        $this->em->flush();
    }

    public function testOnlyUnreadEntriesAreReturned(): void
    {
        $readByFlag = $this->entry('read-by-flag', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $readByFlag);
        $state->setIsRead(true);
        $this->em->persist($state);

        $this->entry('read-by-watermark', '2026-07-11T00:00:00Z');
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        $unread = $this->entry('unread', '2026-07-13T00:00:00Z');

        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), 100);

        self::assertSame([$unread->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testNewestFirst(): void
    {
        $this->entry('older', '2026-07-10T00:00:00Z');
        $this->entry('newer', '2026-07-11T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), 100);

        self::assertSame(['newer', 'older'], array_map(static fn ($l) => $l->title, $lines));
    }

    public function testPoolSizeCapsTheList(): void
    {
        $this->entry('a', '2026-07-10T00:00:00Z');
        $this->entry('b', '2026-07-11T00:00:00Z');
        $this->entry('c', '2026-07-12T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), 2);

        self::assertCount(2, $lines);
        self::assertSame(['c', 'b'], array_map(static fn ($l) => $l->title, $lines));
    }

    public function testLinesForIdsDropsPrunedIds(): void
    {
        $entry = $this->entry('kept', '2026-07-10T00:00:00Z');
        $keptId = $entry->getId();
        self::assertNotNull($keptId);

        $linesById = $this->loader()->linesForIds($this->userId(), [$keptId, 999999]);

        self::assertSame([$keptId], array_keys($linesById));
        self::assertSame('kept', $linesById[$keptId]->title);
    }

    public function testLinesForIdsWithAnEmptyIdListReturnsNothing(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        self::assertSame([], $this->loader()->linesForIds($this->userId(), []));
    }

    /**
     * An empty id list must short-circuit before touching the database: an
     * `IN ()` built by hand is a SQL syntax error, and even where the ORM
     * would tolerate it, a result-only assertion cannot tell "the guard
     * returned early" from "the query ran and happened to find nothing" —
     * both produce []. Counting queries is the only way to pin the guard
     * itself, not just its externally visible result.
     */
    public function testLinesForIdsWithAnEmptyIdListNeverQueriesTheDatabase(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->loader()->linesForIds($this->userId(), []);

        self::assertSame([], $recorder->queries());
    }

    public function testLinesForIdsResolvesMultipleEntries(): void
    {
        $first = $this->entry('first', '2026-07-10T00:00:00Z');
        $second = $this->entry('second', '2026-07-11T00:00:00Z');
        $firstId = $first->getId();
        $secondId = $second->getId();
        self::assertNotNull($firstId);
        self::assertNotNull($secondId);

        $linesById = $this->loader()->linesForIds($this->userId(), [$firstId, $secondId]);

        self::assertSame([$firstId, $secondId], array_keys($linesById));
    }

    public function testLinesForIdsCarriesTheSubscriptionsCustomTitle(): void
    {
        $this->subscription->setCustomTitle('My Custom Feed');
        $this->em->flush();

        $entry = $this->entry('titled', '2026-07-10T00:00:00Z');
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        $linesById = $this->loader()->linesForIds($this->userId(), [$entryId]);

        self::assertSame('My Custom Feed', $linesById[$entryId]->feedName);
    }

    public function testFeedNamePrefersTheSubscriptionsCustomTitle(): void
    {
        $this->subscription->setCustomTitle('My Custom Feed');
        $this->em->flush();
        $this->entry('titled', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), 100);

        self::assertSame('My Custom Feed', $lines[0]->feedName);
    }

    public function testDescriptionPrefersTheSummaryOverTheContentHtml(): void
    {
        $entry = $this->entry('described', '2026-07-10T00:00:00Z');
        $entry->setSummary('Summary text');
        $entry->setContentHtml('<p>Content text</p>');
        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), 100);

        self::assertSame('Summary text', $lines[0]->description);
    }

    public function testFeedNameFallsBackToTheFeedTitleWithoutACustomTitle(): void
    {
        $this->entry('untitled', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), 100);

        self::assertSame('Example', $lines[0]->feedName);
    }

    private function entry(string $guid, string $published): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setPublishedAt(new \DateTimeImmutable($published));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function userId(): int
    {
        return $this->user->getId() ?? 0;
    }

    private function loader(): RecommendationCandidateLoader
    {
        /** @var RecommendationCandidateLoader $loader */
        $loader = self::getContainer()->get(RecommendationCandidateLoader::class);

        return $loader;
    }
}
