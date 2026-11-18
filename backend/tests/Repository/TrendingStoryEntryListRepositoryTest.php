<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryEntryListRepository;
use App\Repository\TrendingStoryListQuery;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingStoryEntryListRepositoryTest extends DbTestCase
{
    private const string ARTICLE = 'https://news.example/article';

    private TrendingFixtures $fixtures;
    private TrendingStoryEntryListRepository $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
        $this->entries = self::getContainer()->get(TrendingStoryEntryListRepository::class);
    }

    public function testJoinedAtByEntryReadsOnlyCopiesTheReaderSees(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-joined-at@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $shown = $this->copyOf($fixtures->feed('alpha'), 'Shown');
        $story = $fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $shown);
        $fixtures->join($story, '2031-05-14 10:00:00', $this->copyOf($fixtures->feed('beta'), 'Visible copy'));
        $unfollowed = $fixtures->feedNobodyFollows('unfollowed');
        $fixtures->join($story, '2031-05-14 11:00:00', $this->copyOf($unfollowed, 'Copy of a feed no longer followed'));

        self::assertEquals(
            [$shown->requireId() => self::at('2031-05-14 10:00:00')],
            $this->joinedAtOf($reader, $story, $shown),
        );
    }

    public function testJoinedAtByEntryReadsACopyInAFeedSwitchedOutAfterTheBuild(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-joined-at-switched@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $shown = $this->copyOf($fixtures->feed('alpha'), 'Shown');
        $story = $fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $shown);
        $switchedOut = $fixtures->feed('switched-out');
        $fixtures->join($story, '2031-05-14 11:00:00', $this->copyOf($switchedOut, 'Switched out later'));

        $this->switchOutOfTrending($reader, $switchedOut);

        self::assertEquals(
            [$shown->requireId() => self::at('2031-05-14 11:00:00')],
            $this->joinedAtOf($reader, $story, $shown),
        );
    }

    public function testJoinedAtByEntryReadsNothingForNoEntries(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-joined-at-none@example.com');

        self::assertSame([], $this->entries->joinedAtByEntry(new TrendingStoryListQuery($reader->requireId(), 1), []));
    }

    /** @return array<int, \DateTimeImmutable> */
    private function joinedAtOf(User $reader, TrendingStory $story, Entry $entry): array
    {
        return $this->entries->joinedAtByEntry(
            new TrendingStoryListQuery($reader->requireId(), $story->requireId()),
            [$entry->requireId()],
        );
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private function copyOf(Feed $feed, string $title): Entry
    {
        return $this->fixtures->article($feed, $title, '2031-05-14 08:00:00', self::ARTICLE);
    }

    private function switchOutOfTrending(User $user, Feed $feed): void
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->changeInclusions(new InclusionChange(trending: false));
        $this->entityManager->flush();
    }
}
