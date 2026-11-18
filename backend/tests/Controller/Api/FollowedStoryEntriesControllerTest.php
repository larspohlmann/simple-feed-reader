<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class FollowedStoryEntriesControllerTest extends ApiTestCase
{
    private const string ARTICLE = 'https://news.example/article';

    private KernelBrowser $client;
    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->set(
            NaiveUtcClock::class,
            new NaiveUtcClock(new MockClock('2031-05-14 10:00:00', 'UTC')),
        );
        $this->fixtures = new TrendingFixtures($this->entityManager());
    }

    public function testItListsTheStorysWholeHistoryNewestFirst(): void
    {
        $reader = $this->signInAsOwner('story-list-history@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Middle', '2031-05-14 08:00:00'),
            $this->fixtures->entry($beta, 'Newest', '2031-05-14 09:00:00'),
        );
        $outOfBothWindows = $this->fixtures->entry($gamma, 'Out of both windows', '2031-05-01 08:00:00');
        $this->fixtures->join($story, '2031-05-14 09:00:00', $outOfBothWindows);
        $this->follow($story);

        self::assertSame(['Newest', 'Middle', 'Out of both windows'], $this->titles($this->page($story)));
    }

    public function testItPagesWithTheUsualCursor(): void
    {
        $reader = $this->signInAsOwner('story-list-pages@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Third', '2031-05-14 07:00:00'),
            $this->fixtures->entry($beta, 'First', '2031-05-14 09:00:00'),
            $this->fixtures->entry($gamma, 'Second', '2031-05-14 08:00:00'),
        );
        $this->follow($story);
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 11:00:00',
            $this->fixtures->entry($this->reloaded($alpha), 'Oldest, joined late', '2031-05-14 06:00:00'),
        );

        $first = $this->page($story, ['limit' => 2]);
        self::assertSame(['First', 'Second'], $this->titles($first));
        self::assertIsString($first['nextCursor']);

        $second = $this->page($story, ['limit' => 2, 'cursor' => $first['nextCursor']]);
        self::assertSame(['Third', 'Oldest, joined late'], $this->titles($second));
        self::assertIsString($second['nextCursor']);

        $last = $this->page($story, ['limit' => 2, 'cursor' => $second['nextCursor']]);
        self::assertSame([], $this->titles($last));
        self::assertNull($last['nextCursor']);
    }

    public function testItPagesAcrossMembersPublishedAtTheSameTime(): void
    {
        $reader = $this->signInAsOwner('story-list-tie@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);

        $first = $this->page($story, ['limit' => 1]);
        self::assertSame(['Second'], $this->titles($first));
        self::assertIsString($first['nextCursor']);
        $second = $this->page($story, ['limit' => 1, 'cursor' => $first['nextCursor']]);
        self::assertSame(['First'], $this->titles($second));
        self::assertIsString($second['nextCursor']);
        self::assertSame([], $this->titles($this->page($story, ['limit' => 1, 'cursor' => $second['nextCursor']])));
    }

    public function testItListsOnlyUnreadEntriesWhenAsked(): void
    {
        $reader = $this->signInAsOwner('story-list-unread@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $viewed = $this->fixtures->entry($alpha, 'Viewed', '2031-05-14 09:00:00');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $viewed,
            $this->fixtures->entry($beta, 'Unread', '2031-05-14 08:00:00'),
        );
        $this->follow($story);
        $this->client->request(
            'PATCH',
            \sprintf('/api/entries/%d/state', $viewed->requireId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"isViewed":true}',
        );
        self::assertResponseIsSuccessful();

        self::assertSame(['Unread'], $this->titles($this->page($story, ['unread' => 1])));
        self::assertSame(['Viewed', 'Unread'], $this->titles($this->page($story)));
    }

    public function testItListsOldestFirstWhenAsked(): void
    {
        $reader = $this->signInAsOwner('story-list-oldest@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Newer', '2031-05-14 09:00:00'),
            $this->fixtures->entry($beta, 'Older', '2031-05-14 08:00:00'),
        );
        $this->follow($story);

        self::assertSame(['Older', 'Newer'], $this->titles($this->page($story, ['order' => 'asc'])));
    }

    public function testFeedsTheReaderStillFollowsAreListedWhateverTheirTrendingSwitch(): void
    {
        $reader = $this->signInAsOwner('story-list-included@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $unfollowed = $this->fixtures->feedNobodyFollows('unfollowed');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Included', '2031-05-14 09:00:00'),
            $this->fixtures->entry($beta, 'Switched out after the build', '2031-05-14 08:00:00'),
            $this->fixtures->entry($unfollowed, 'Not followed', '2031-05-14 07:00:00'),
        );
        $this->switchOutOfTrending($reader, $beta);
        $this->follow($story);

        self::assertSame(['Included', 'Switched out after the build'], $this->titles($this->page($story)));
    }

    public function testMembersJoinedAfterTheVisitAreMarkedNew(): void
    {
        $reader = $this->signInAsOwner('story-list-new@example.com');
        [$alpha, $beta, $gamma, $delta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $atTheVisit = $this->fixtures->entry($this->reloaded($gamma), 'At the visit', '2031-05-14 10:00:00');
        $joinedLater = $this->fixtures->entry($this->reloaded($delta), 'After the visit', '2031-05-14 08:00:00');
        $this->fixtures->join($this->reloaded($story), '2031-05-14 10:00:00', $atTheVisit);
        $newestJoinedLater = $this->fixtures->entry($this->reloaded($delta), 'Newest', '2031-05-14 09:30:00');
        $this->fixtures->join($this->reloaded($story), '2031-05-14 11:00:00', $joinedLater, $newestJoinedLater);

        $page = $this->page($story);

        self::assertSame('2031-05-14T10:00:00+00:00', $page['lastOpenedAt']);
        self::assertSame([$newestJoinedLater->requireId(), $joinedLater->requireId()], $page['newEntryIds']);
    }

    public function testEachRowSaysWhenItJoinedTheStory(): void
    {
        $reader = $this->signInAsOwner('story-list-joined-at@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Joined first', '2031-05-14 09:00:00'),
        );
        $this->follow($story);
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 11:00:00',
            $this->fixtures->entry($this->reloaded($beta), 'Joined later', '2031-05-14 08:00:00'),
        );
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 10:30:00',
            $this->fixtures->entry($this->reloaded($gamma), 'Joined in between', '2031-05-14 07:00:00'),
        );

        $entries = $this->entries($this->page($story));

        self::assertSame(
            ['Joined first', 'Joined later', 'Joined in between'],
            array_column($entries, 'title'),
        );
        self::assertSame(
            ['2031-05-14T09:00:00+00:00', '2031-05-14T11:00:00+00:00', '2031-05-14T10:30:00+00:00'],
            array_column($entries, 'joinedAt'),
        );
    }

    public function testASecondPageMarksItsNewRowsToo(): void
    {
        $reader = $this->signInAsOwner('story-list-new-second-page@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'First', '2031-05-14 09:00:00'),
            $this->fixtures->entry($beta, 'Second', '2031-05-14 08:00:00'),
            $this->fixtures->entry($gamma, 'Third', '2031-05-14 07:00:00'),
        );
        $this->follow($story);
        $joinedLate = $this->fixtures->entry($this->reloaded($alpha), 'Oldest, joined late', '2031-05-14 06:00:00');
        $this->fixtures->join($this->reloaded($story), '2031-05-14 11:00:00', $joinedLate);

        $first = $this->page($story, ['limit' => 2]);
        self::assertIsString($first['nextCursor']);
        $second = $this->page($story, ['limit' => 2, 'cursor' => $first['nextCursor']]);

        self::assertSame([], $first['newEntryIds']);
        self::assertSame(['Third', 'Oldest, joined late'], $this->titles($second));
        self::assertSame([$joinedLate->requireId()], $second['newEntryIds']);
    }

    public function testACollapsedRowJoinsAtItsLatestVisibleCopy(): void
    {
        $reader = $this->signInAsOwner('story-list-joined-at-copy@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $unfollowed = $this->fixtures->feedNobodyFollows('unfollowed');
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $this->copyOf($alpha, 'Shown'));
        $this->follow($story);
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 11:00:00',
            $this->copyOf($this->reloaded($beta), 'Copy'),
        );
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 12:00:00',
            $this->copyOf($this->reloaded($unfollowed), 'Unseen copy'),
        );

        $entries = $this->entries($this->page($story));

        self::assertSame(['Shown'], array_column($entries, 'title'));
        self::assertSame(['2031-05-14T11:00:00+00:00'], array_column($entries, 'joinedAt'));
    }

    public function testACopyJoinedAfterTheVisitMarksItsRowNew(): void
    {
        $reader = $this->signInAsOwner('story-list-new-copy@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $shown = $this->copyOf($alpha, 'Shown');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $shown,
            $this->fixtures->entry($beta, 'Untouched', '2031-05-14 07:00:00'),
        );
        $this->follow($story);
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 11:00:00',
            $this->copyOf($this->reloaded($gamma), 'Copy'),
        );

        $page = $this->page($story);

        self::assertSame(['Shown', 'Untouched'], $this->titles($page));
        self::assertSame([$shown->requireId()], $page['newEntryIds']);
    }

    public function testACopyTheReaderCannotSeeMarksNothingNew(): void
    {
        $reader = $this->signInAsOwner('story-list-new-unseen@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $unfollowed = $this->fixtures->feedNobodyFollows('unfollowed');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->copyOf($alpha, 'Shown'),
            $this->fixtures->entry($beta, 'Untouched', '2031-05-14 07:00:00'),
        );
        $this->follow($story);
        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 11:00:00',
            $this->copyOf($this->reloaded($unfollowed), 'Unseen copy'),
        );

        self::assertSame([], $this->page($story)['newEntryIds']);
    }

    public function testACopyInAFeedSwitchedOutAfterTheBuildStillMarksItsRowNew(): void
    {
        $reader = $this->signInAsOwner('story-list-new-switched-out@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $shown = $this->copyOf($alpha, 'Shown');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $shown,
            $this->fixtures->entry($beta, 'Untouched', '2031-05-14 07:00:00'),
        );
        $this->follow($story);
        $copy = $this->copyOf($this->reloaded($gamma), 'Copy');
        $this->fixtures->join($this->reloaded($story), '2031-05-14 11:00:00', $copy);
        $this->switchOutOfTrending($reader, $this->reloaded($gamma));

        self::assertSame([$shown->requireId()], $this->page($story)['newEntryIds']);
    }

    public function testAMemberOfAnotherStoryMarksNothingNew(): void
    {
        $reader = $this->signInAsOwner('story-list-new-other@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $shown = $this->copyOf($alpha, 'Shown');
        $story = $this->fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', $shown);
        $this->follow($story);
        $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 11:00:00',
            $this->copyOf($this->reloaded($beta), 'Elsewhere'),
        );

        self::assertSame([], $this->page($story)['newEntryIds']);
    }

    public function testListingDoesNotRecordAVisit(): void
    {
        $reader = $this->signInAsOwner('story-list-no-visit@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $joined = $this->fixtures->entry($this->reloaded($gamma), 'After the visit', '2031-05-14 11:00:00');
        $this->fixtures->join($this->reloaded($story), '2031-05-14 11:00:00', $joined);

        $first = $this->page($story);
        $second = $this->page($story);

        self::assertSame([$joined->requireId()], $second['newEntryIds']);
        self::assertSame($first['lastOpenedAt'], $second['lastOpenedAt']);
        self::assertSame($first['newEntryIds'], $second['newEntryIds']);
    }

    public function testAStoryNotFollowedIsNotFound(): void
    {
        $reader = $this->signInAsOwner('story-list-unfollowed@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);

        $this->client->request('GET', \sprintf('/api/entries/trending-stories/%d', $story->requireId()));

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        self::assertSame('trending_story_not_followed', $this->payload($this->client)['type']);
    }

    public function testAnotherReadersFollowOpensNothing(): void
    {
        $reader = $this->signInAsOwner('story-list-own@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $other = $this->factory()->create('story-list-own-other@example.com');
        $this->authenticate($this->client, $other);

        $this->client->request('GET', \sprintf('/api/entries/trending-stories/%d', $story->requireId()));

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
    }

    public function testRowsCarryTheUsualEntryStateAndEnrichment(): void
    {
        $reader = $this->signInAsOwner('story-list-state@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $starred = $this->fixtures->entry($alpha, 'Starred', '2031-05-14 09:00:00');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $starred,
            $this->fixtures->postSharing($beta, 'Sharing', '2031-05-14 08:00:00', 'https://news.example/card'),
        );
        $this->follow($story);
        $this->client->request(
            'PATCH',
            \sprintf('/api/entries/%d/state', $starred->requireId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"isFavorite":true}',
        );
        self::assertResponseIsSuccessful();

        $entries = $this->entries($this->page($story));

        self::assertSame([true, false], array_column($entries, 'isFavorite'));
        self::assertSame([[], ['https://news.example/card']], array_map(
            static fn (mixed $cards): array => \is_array($cards) ? array_column($cards, 'url') : [],
            array_column($entries, 'linkCards'),
        ));
    }

    public function testRejectsAnonymous(): void
    {
        $this->client->request('GET', '/api/entries/trending-stories/1');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function signInAsOwner(string $email): User
    {
        $reader = $this->signIn($this->client, $email);
        $this->fixtures = $this->fixtures->ownedBy($reader);

        return $reader;
    }

    /** @return array{Feed, Feed, Feed, Feed} four article feeds the reader follows */
    private function followedFeeds(User $reader): array
    {
        $feeds = [
            $this->fixtures->feed('alpha'),
            $this->fixtures->feed('beta'),
            $this->fixtures->feed('gamma'),
            $this->fixtures->feed('delta'),
        ];
        $this->fixtures->subscribe($reader, ...$feeds);

        return $feeds;
    }

    private function storyOf(Feed $first, Feed $second): TrendingStory
    {
        return $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($first, 'First', '2031-05-14 09:00:00'),
            $this->fixtures->entry($second, 'Second', '2031-05-14 09:00:00'),
        );
    }

    /** Every copy shares one address, so a feed's copy collapses into the lowest-id copy the reader sees. */
    private function copyOf(Feed $feed, string $title): Entry
    {
        return $this->fixtures->article($feed, $title, '2031-05-14 08:00:00', self::ARTICLE);
    }

    /**
     * Requests after the first reset the entity manager, which forgets what the test built before them.
     *
     * @template T of TrendingStory|Feed
     *
     * @param T $entity
     *
     * @return T
     */
    private function reloaded(TrendingStory|Feed $entity): TrendingStory|Feed
    {
        $reloaded = $this->entityManager()->find($entity::class, $entity->requireId());
        self::assertInstanceOf($entity::class, $reloaded);

        return $reloaded;
    }

    private function switchOutOfTrending(User $user, Feed $feed): void
    {
        $subscription = $this->entityManager()->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->changeInclusions(new InclusionChange(trending: false));
        $this->entityManager()->flush();
    }

    private function follow(TrendingStory $story): void
    {
        $this->client->request('PUT', \sprintf('/api/trending/stories/%d/follow', $story->requireId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, int|string|null> $parameters
     *
     * @return array<string, mixed>
     */
    private function page(TrendingStory $story, array $parameters = []): array
    {
        $this->client->request(
            'GET',
            \sprintf('/api/entries/trending-stories/%d', $story->requireId()),
            $parameters,
        );
        self::assertResponseIsSuccessful();

        return $this->payload($this->client);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $page): array
    {
        $entries = $page['entries'];
        self::assertIsArray($entries);

        return array_values(array_map(static function (mixed $entry): array {
            self::assertIsArray($entry);

            /** @var array<string, mixed> $entry */
            return $entry;
        }, $entries));
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<mixed>
     */
    private function titles(array $page): array
    {
        return array_column($this->entries($page), 'title');
    }
}
