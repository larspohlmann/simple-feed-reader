<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
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
        $alpha = $this->fixtures->feed('alpha');
        $beta = $this->fixtures->feed('beta');
        $switchedOut = $this->fixtures->feed('switched-out');
        $this->fixtures->subscribe($reader, $alpha, $beta, $switchedOut);
        $shown = $this->copyOf($alpha, 'Shown');
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $shown);
        $this->fixtures->join($story, '2031-05-14 10:00:00', $this->copyOf($beta, 'Visible copy'));
        $this->fixtures->join($story, '2031-05-14 11:00:00', $this->copyOf($switchedOut, 'Excluded copy'));
        $this->switchOutOfTrending($reader, $switchedOut);

        $joinedAt = $this->entries->joinedAtByEntry(
            new TrendingStoryListQuery($reader->requireId(), $story->requireId()),
            [$shown->requireId()],
        );

        self::assertEquals(
            [$shown->requireId() => new \DateTimeImmutable('2031-05-14 10:00:00', new \DateTimeZone('UTC'))],
            $joinedAt,
        );
    }

    public function testJoinedAtByEntryReadsNothingForNoEntries(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-joined-at-none@example.com');

        self::assertSame([], $this->entries->joinedAtByEntry(new TrendingStoryListQuery($reader->requireId(), 1), []));
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
