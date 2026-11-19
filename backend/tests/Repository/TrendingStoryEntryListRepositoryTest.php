<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryEntryListRepository;
use App\Repository\TrendingStoryListQuery;
use App\Service\Curation\Trending\Model\StoryHeadingModel;
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

    public function testHeadingOfPrefersTheLlmTitleThenTheFallback(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-title@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $written = $this->storyIn($fixtures, $fixtures->feed('written'));
        $fallenBack = $this->storyIn($fixtures, $fixtures->feed('fallen-back'));
        $this->storeTitles($written, 'Hafenstreik in Hamburg', 'Hafenarbeiter in Hamburg streiken', 'Die Arbeit ruht.');
        $this->storeTitles($fallenBack, 'Hafenstreik in Hamburg', null);

        self::assertEquals(
            new StoryHeadingModel('Hafenarbeiter in Hamburg streiken', 'Die Arbeit ruht.'),
            $this->headingOf($reader, $written),
        );
        self::assertEquals(
            new StoryHeadingModel('Hafenstreik in Hamburg', null),
            $this->headingOf($reader, $fallenBack),
        );
    }

    public function testTheHeadingOfAStoryWithoutStoredTitlesHasNoSynopsis(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-heading-leftover@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $story = $this->storyIn($fixtures, $fixtures->feed('leftover'));
        $this->storeTitles($story, null, null, 'A leftover synopsis.');

        self::assertEquals(new StoryHeadingModel('Member', null), $this->headingOf($reader, $story));
    }

    public function testAStoryWithoutStoredTitlesIsTitledByItsNewestSubscribedArticle(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-title-headline@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $story = $fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 10:00:00',
            $fixtures->entry($fixtures->feed('older'), 'Hafenstreik', '2031-05-14 08:00:00'),
            $fixtures->entry($fixtures->feed('newer'), 'Neue Gespräche', '2031-05-14 09:00:00'),
            $fixtures->entry($fixtures->feed('post', SocialNetwork::Mastodon), 'A newer post', '2031-05-14 09:30:00'),
            $fixtures->entry($fixtures->feedNobodyFollows('unsubscribed'), 'Not subscribed', '2031-05-14 09:45:00'),
        );

        self::assertSame('Neue Gespräche', $this->headingOf($reader, $story)?->title);
    }

    public function testTheHeadingOfAStoryWithNoTitleAnywhereIsNull(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-heading-titleless@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $story = $this->storyIn($fixtures, $fixtures->feedNobodyFollows('unsubscribed-only'));

        self::assertNull($this->headingOf($reader, $story));
    }

    public function testHeadingOfReadsNothingFromAnotherUsersStory(): void
    {
        $reader = $this->fixtures->reader('story-entry-list-title-owner@example.com');
        $fixtures = $this->fixtures->ownedBy($reader);
        $story = $this->storyIn($fixtures, $fixtures->feed('owned'));
        $other = $this->fixtures->reader('story-entry-list-title-other@example.com');

        self::assertNull($this->headingOf($other, $story));
    }

    private function storyIn(TrendingFixtures $fixtures, Feed $feed): TrendingStory
    {
        return $fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $fixtures->entry($feed, 'Member', '2031-05-14 09:00:00'),
        );
    }

    private function headingOf(User $reader, TrendingStory $story): ?StoryHeadingModel
    {
        return $this->entries->headingOf(new TrendingStoryListQuery($reader->requireId(), $story->requireId()));
    }

    private function storeTitles(
        TrendingStory $story,
        ?string $fallbackTitle,
        ?string $llmTitle,
        ?string $llmSynopsis = null,
    ): void {
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE trending_story SET fallback_title = ?, llm_title = ?, llm_synopsis = ? WHERE id = ?',
            [$fallbackTitle, $llmTitle, $llmSynopsis, $story->requireId()],
        );
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
