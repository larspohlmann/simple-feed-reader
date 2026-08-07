<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationHistoryLoader;
use App\Tests\DbTestCase;

final class RecommendationHistoryLoaderTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('history@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $this->em->flush();
    }

    public function testEachEntryAppearsOnlyInItsHighestSection(): void
    {
        $entryA = $this->entry('A', '2026-07-10T00:00:00Z');
        $entryB = $this->entry('B', '2026-07-11T00:00:00Z');
        $entryC = $this->entry('C', '2026-07-12T00:00:00Z');
        $this->entry('D', '2026-07-13T00:00:00Z');
        $entryE = $this->entry('E', '2026-07-14T00:00:00Z');

        $stateA = new EntryState($this->user, $entryA);
        $stateA->setIsFavorite(true);
        $stateA->setIsKept(true);
        $stateA->markViewed(new \DateTimeImmutable('2026-07-10T09:00:00Z'));
        $this->em->persist($stateA);

        $stateB = new EntryState($this->user, $entryB);
        $stateB->setIsKept(true);
        $stateB->markViewed(new \DateTimeImmutable('2026-07-11T09:00:00Z'));
        $this->em->persist($stateB);

        $stateC = new EntryState($this->user, $entryC);
        $stateC->markViewed(new \DateTimeImmutable('2026-07-12T09:00:00Z'));
        $this->em->persist($stateC);

        $stateE = new EntryState($this->user, $entryE);
        $stateE->setIsFavorite(true);
        $this->em->persist($stateE);

        $this->em->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['E', 'A'], array_map(static fn ($l) => $l->title, $history->favorites));
        self::assertSame(['B'], array_map(static fn ($l) => $l->title, $history->kept));
        self::assertSame(['C'], array_map(static fn ($l) => $l->title, $history->viewed));
        self::assertNull($history->favorites[0]->entryId);
    }

    public function testCapsApplyNewestFirst(): void
    {
        $entryC = $this->entry('C', '2026-07-10T00:00:00Z');
        $entryB = $this->entry('B', '2026-07-11T00:00:00Z');
        $entryF = $this->entry('F', '2026-07-12T00:00:00Z');

        $stateC = new EntryState($this->user, $entryC);
        $stateC->markViewed(new \DateTimeImmutable('2026-07-15T10:00:00Z'));
        $this->em->persist($stateC);

        $stateB = new EntryState($this->user, $entryB);
        $stateB->setIsKept(true);
        $stateB->markViewed(new \DateTimeImmutable('2026-07-15T11:00:00Z'));
        $this->em->persist($stateB);

        $stateF = new EntryState($this->user, $entryF);
        $stateF->markViewed(new \DateTimeImmutable('2026-07-15T12:00:00Z'));
        $this->em->persist($stateF);

        $this->em->flush();

        $history = $this->loader()->load($this->userId(), $this->settings(viewedCap: 1));

        self::assertSame(['F'], array_map(static fn ($l) => $l->title, $history->viewed));
    }

    public function testViewedOrdersByViewedAtNotEffectiveDate(): void
    {
        // F published before C but viewed after it → F first.
        $entryF = $this->entry('F', '2026-07-01T00:00:00Z');
        $entryC = $this->entry('C', '2026-07-20T00:00:00Z');

        $stateF = new EntryState($this->user, $entryF);
        $stateF->markViewed(new \DateTimeImmutable('2026-07-25T09:00:00Z'));
        $this->em->persist($stateF);

        $stateC = new EntryState($this->user, $entryC);
        $stateC->markViewed(new \DateTimeImmutable('2026-07-25T08:00:00Z'));
        $this->em->persist($stateC);

        $this->em->flush();

        $history = $this->loader()->load($this->userId(), $this->settings());

        self::assertSame(['F', 'C'], array_map(static fn ($l) => $l->title, $history->viewed));
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

    private function settings(
        int $favoritesCap = 40,
        int $keptCap = 40,
        int $viewedCap = 80,
    ): EffectiveRecommendationSettings {
        return new EffectiveRecommendationSettings(
            guidancePrompt: null,
            favoritesCap: $favoritesCap,
            keptCap: $keptCap,
            viewedCap: $viewedCap,
            candidatePoolSize: 1000,
            picksLimit: 100,
            contextWindow: 32768,
            contextWindowSource: 'fallback',
            debugEnabled: false,
        );
    }

    private function userId(): int
    {
        return $this->user->getId() ?? 0;
    }

    private function loader(): RecommendationHistoryLoader
    {
        /** @var RecommendationHistoryLoader $loader */
        $loader = self::getContainer()->get(RecommendationHistoryLoader::class);

        return $loader;
    }
}
