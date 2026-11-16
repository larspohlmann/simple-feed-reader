<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\TrendingSnapshot;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class TrendingStoryFollowControllerTest extends ApiTestCase
{
    private const string AUTHOR = 'https://bsky.app/profile/ann.example';

    private KernelBrowser $client;
    private TrendingFixtures $fixtures;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->clock = new MockClock('2031-05-14 10:00:00', 'UTC');
        self::getContainer()->set(NaiveUtcClock::class, new NaiveUtcClock($this->clock));
        $this->fixtures = new TrendingFixtures($this->entityManager());
    }

    public function testFollowingAStoryListsItWithNothingNew(): void
    {
        $reader = $this->signIn($this->client, 'follow-listed@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Alpha leads', '2031-05-14 09:00:00'),
            $this->fixtures->entry($beta, 'Beta follows', '2031-05-14 08:00:00'),
        );

        $this->follow($story);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([[
            'id' => $story->requireId(),
            'headline' => 'Alpha leads',
            'newCount' => 0,
            'quiet' => false,
            'lastJoinedAt' => '2031-05-14T09:00:00+00:00',
        ]], $this->followedStories());
    }

    public function testTheFollowedListSaysWhenTheNextBuildIsDue(): void
    {
        $this->signIn($this->client, 'follow-next-build@example.com');
        foreach ([TrendingWindow::Day, TrendingWindow::Week] as $window) {
            $this->snapshots()->recordSnapshot(new TrendingBuildReportModel(
                $window,
                new \DateTimeImmutable('2031-05-14 09:50:00', new \DateTimeZone('UTC')),
                0,
                0,
                1,
                1,
                new StoryMatchModel([], [], 0),
                0,
            ));
        }

        $this->client->request('GET', '/api/trending/followed');

        self::assertResponseIsSuccessful();
        self::assertSame('2031-05-14T10:00:00+00:00', $this->payload($this->client)['nextBuildDueAt']);
    }

    public function testFollowingTwiceKeepsTheFirstVisit(): void
    {
        $reader = $this->signIn($this->client, 'follow-twice@example.com');
        [$alpha, $beta, $gamma, $delta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->entry($gamma, 'Gamma joins', '2031-05-14 11:00:00'),
            $this->fixtures->entry($delta, 'Delta joins', '2031-05-14 11:00:00'),
        );
        $this->clock->modify('2031-05-14 12:00:00');

        $this->follow($story);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([2], $this->newCounts());
    }

    public function testFollowingAnUnknownStoryIsNotFound(): void
    {
        $reader = $this->signIn($this->client, 'follow-unknown@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);

        $this->client->request('PUT', \sprintf('/api/trending/stories/%d/follow', $story->requireId() + 1));

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        self::assertSame('trending_story_not_found', $this->payload($this->client)['type']);
        self::assertSame([], $this->followedStories());
    }

    public function testUnfollowingRemovesTheRowAndIsIdempotent(): void
    {
        $reader = $this->signIn($this->client, 'follow-unfollow@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $kept = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $this->follow($kept);

        $this->unfollow($story);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->unfollow($story);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        self::assertSame([$kept->requireId()], $this->followedStoryIds());
    }

    public function testMembersJoinedAfterTheVisitCountAsNew(): void
    {
        $reader = $this->signIn($this->client, 'follow-new@example.com');
        [$alpha, $beta, $gamma, $delta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $this->fixtures->join(
            $story,
            '2031-05-14 10:00:00',
            $this->fixtures->entry($alpha, 'Joined as the reader looked', '2031-05-14 10:00:00'),
        );

        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->entry($gamma, 'Gamma joins', '2031-05-14 11:00:00'),
            $this->fixtures->entry($delta, 'Delta joins', '2031-05-14 11:00:00'),
        );

        self::assertSame([2], $this->newCounts());
    }

    public function testAnOldArticleGroupedInNowCountsAsNew(): void
    {
        $reader = $this->signIn($this->client, 'follow-old-article@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);

        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->entry($gamma, 'Published three days ago', '2031-05-11 10:00:00'),
        );

        self::assertSame([1], $this->newCounts());
    }

    public function testNewCountsAnArticleOnceAndAnAccountOnce(): void
    {
        $reader = $this->signIn($this->client, 'follow-once@example.com');
        [$alpha, $beta, , , $ann, $ben] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);

        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->article($alpha, 'One article', '2031-05-14 11:00:00', 'https://news.example/one'),
            $this->fixtures->article($beta, 'One article', '2031-05-14 11:00:00', 'https://news.example/one'),
            $this->post($ann, 'First post', self::AUTHOR),
            $this->post($ben, 'Second post', self::AUTHOR),
        );

        self::assertSame([2], $this->newCounts());
    }

    public function testAPostNamingNoAccountCountsByItsFeed(): void
    {
        $reader = $this->signIn($this->client, 'follow-no-account@example.com');
        [$alpha, $beta, , , $ann, $ben] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);

        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->post($ann, 'First post', null),
            $this->post($ann, 'Second post', null),
            $this->post($ben, 'Third post', null),
        );

        self::assertSame([2], $this->newCounts());
    }

    public function testOnlyFeedsTheReaderFollowsAndIncludesInTrendingCount(): void
    {
        $reader = $this->signIn($this->client, 'follow-included@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $unfollowed = $this->fixtures->feed('unfollowed');
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $this->switchOutOfTrending($reader, $beta);

        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->entry($beta, 'Switched out', '2031-05-14 11:00:00'),
            $this->fixtures->entry($gamma, 'Included', '2031-05-14 11:00:00'),
            $this->fixtures->entry($unfollowed, 'Not followed', '2031-05-14 11:00:00'),
        );

        self::assertSame([1], $this->newCounts());
    }

    public function testOpeningClearsTheNewCount(): void
    {
        $reader = $this->signIn($this->client, 'follow-opened@example.com');
        [$alpha, $beta, $gamma, $delta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->entry($gamma, 'Gamma joins', '2031-05-14 11:00:00'),
        );
        $this->clock->modify('2031-05-14 12:00:00');

        $this->open($story);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([0], $this->newCounts());
        $this->open($story);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->fixtures->join(
            $this->reloaded($story),
            '2031-05-14 12:00:01',
            $this->fixtures->entry($this->reloaded($delta), 'Delta joins', '2031-05-14 12:00:01'),
        );
        self::assertSame([1], $this->newCounts());
    }

    public function testOpeningAStoryNotFollowedIsNotFound(): void
    {
        $reader = $this->signIn($this->client, 'follow-open-unfollowed@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);

        $this->open($story);

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        self::assertSame('trending_story_not_followed', $this->payload($this->client)['type']);
        self::assertSame('Trending story not followed', $this->payload($this->client)['title']);
    }

    public function testAStoryWithNothingJoinedForTwoDaysIsQuiet(): void
    {
        $reader = $this->signIn($this->client, 'follow-quiet@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $quiet = $this->storyOf($alpha, $beta, '2031-05-14 09:00:00');
        $active = $this->storyOf($alpha, $beta, '2031-05-14 10:00:00');
        $this->clock->modify('2031-05-16 09:00:00');
        $this->follow($quiet);
        $this->follow($active);

        $stories = $this->followedStories();

        self::assertSame([$active->requireId(), $quiet->requireId()], array_column($stories, 'id'));
        self::assertSame([false, true], array_column($stories, 'quiet'));
    }

    public function testTheHeadlinePrefersTheNewestArticleOverANewerPost(): void
    {
        $reader = $this->signIn($this->client, 'follow-headline@example.com');
        [$alpha, $beta, , , $ann] = $this->followedFeeds($reader);
        $unfollowed = $this->fixtures->feed('unfollowed');
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($alpha, 'Older article', '2031-05-14 07:00:00'),
            $this->fixtures->entry($beta, 'Newest article', '2031-05-14 08:00:00'),
            $this->fixtures->entry($unfollowed, 'An article the reader does not follow', '2031-05-14 08:30:00'),
            $this->post($ann, 'A newer post', null),
        );

        $this->follow($story);

        self::assertSame(['Newest article'], array_column($this->followedStories(), 'headline'));
    }

    public function testEachStoryIsHeadedByItsOwnMembers(): void
    {
        $reader = $this->signIn($this->client, 'follow-headline-own@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $older = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 08:00:00',
            $this->fixtures->entry($alpha, 'Older story', '2031-05-14 08:00:00'),
        );
        $newer = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($beta, 'Newer story', '2031-05-14 09:00:00'),
        );
        $this->follow($older);
        $this->follow($newer);

        self::assertSame(['Newer story', 'Older story'], array_column($this->followedStories(), 'headline'));
    }

    public function testAStoryOfPostsAloneIsHeadedByItsNewestPost(): void
    {
        $reader = $this->signIn($this->client, 'follow-headline-posts@example.com');
        [, , , , $ann, $ben] = $this->followedFeeds($reader);
        $story = $this->fixtures->story(
            TrendingWindow::Day,
            '2031-05-14 09:00:00',
            $this->fixtures->entry($ann, 'Older post', '2031-05-14 07:00:00'),
            $this->fixtures->entry($ben, 'Newer post', '2031-05-14 08:00:00'),
        );

        $this->follow($story);

        self::assertSame(['Newer post'], array_column($this->followedStories(), 'headline'));
    }

    public function testAStoryTheReaderCanNoLongerSeeStaysWithNoHeadline(): void
    {
        $reader = $this->signIn($this->client, 'follow-unseen@example.com');
        [$alpha, $beta, $gamma] = $this->followedFeeds($reader);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);
        $this->fixtures->join(
            $story,
            '2031-05-14 11:00:00',
            $this->fixtures->entry($gamma, 'Gamma joins', '2031-05-14 11:00:00'),
        );

        $this->entityManager()->getConnection()
            ->executeStatement('DELETE FROM subscription WHERE user_id = ?', [$reader->requireId()]);

        $stories = $this->followedStories();
        self::assertSame([$story->requireId()], array_column($stories, 'id'));
        self::assertSame([null], array_column($stories, 'headline'));
        self::assertSame([0], array_column($stories, 'newCount'));
    }

    public function testOtherReadersFollowsAreTheirOwn(): void
    {
        $reader = $this->signIn($this->client, 'follow-own@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $other = $this->factory()->create('follow-own-other@example.com');
        $this->fixtures->subscribe($other, $alpha, $beta);
        $story = $this->storyOf($alpha, $beta);
        $this->follow($story);

        $this->authenticate($this->client, $other);
        self::assertSame([], $this->followedStories());
        $this->open($story);
        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        $this->follow($story);
        $this->unfollow($story);

        $this->authenticate($this->client, $reader);
        self::assertSame([$story->requireId()], $this->followedStoryIds());
    }

    public function testFollowedStoriesAreNewestJoinedFirst(): void
    {
        $reader = $this->signIn($this->client, 'follow-order@example.com');
        [$alpha, $beta] = $this->followedFeeds($reader);
        $oldest = $this->storyOf($alpha, $beta, '2031-05-14 08:00:00');
        $newer = $this->storyOf($alpha, $beta, '2031-05-14 09:00:00');
        $newest = $this->storyOf($alpha, $beta, '2031-05-14 09:00:00');
        $this->follow($newer);
        $this->follow($oldest);
        $this->follow($newest);

        self::assertSame(
            [$newest->requireId(), $newer->requireId(), $oldest->requireId()],
            $this->followedStoryIds(),
        );
    }

    #[DataProvider('routes')]
    public function testRejectsAnonymous(string $method, string $path): void
    {
        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'follow' => ['PUT', '/api/trending/stories/1/follow'];
        yield 'unfollow' => ['DELETE', '/api/trending/stories/1/follow'];
        yield 'opened' => ['POST', '/api/trending/stories/1/opened'];
        yield 'followed' => ['GET', '/api/trending/followed'];
    }

    private function snapshots(): TrendingSnapshotRepository
    {
        $repository = $this->entityManager()->getRepository(TrendingSnapshot::class);
        self::assertInstanceOf(TrendingSnapshotRepository::class, $repository);

        return $repository;
    }

    /** @return array{Feed, Feed, Feed, Feed, Feed, Feed} four article feeds and two social accounts the reader follows */
    private function followedFeeds(User $reader): array
    {
        $feeds = [
            $this->fixtures->feed('alpha'),
            $this->fixtures->feed('beta'),
            $this->fixtures->feed('gamma'),
            $this->fixtures->feed('delta'),
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
            $this->fixtures->feed('ben', SocialNetwork::Bluesky),
        ];
        $this->fixtures->subscribe($reader, ...$feeds);

        return $feeds;
    }

    private function storyOf(Feed $first, Feed $second, string $joinedAt = '2031-05-14 09:00:00'): TrendingStory
    {
        return $this->fixtures->story(
            TrendingWindow::Day,
            $joinedAt,
            $this->fixtures->entry($first, 'First', $joinedAt),
            $this->fixtures->entry($second, 'Second', $joinedAt),
        );
    }

    private function post(Feed $feed, string $title, ?string $authorUrl): Entry
    {
        $post = $this->fixtures->entry($feed, $title, '2031-05-14 10:30:00');
        $post->setAuthor(new EntryAuthor('Ann', $authorUrl));

        return $post;
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
    }

    private function unfollow(TrendingStory $story): void
    {
        $this->client->request('DELETE', \sprintf('/api/trending/stories/%d/follow', $story->requireId()));
    }

    private function open(TrendingStory $story): void
    {
        $this->client->request('POST', \sprintf('/api/trending/stories/%d/opened', $story->requireId()));
    }

    /** @return list<array<string, mixed>> */
    private function followedStories(): array
    {
        $this->client->request('GET', '/api/trending/followed');
        self::assertResponseIsSuccessful();
        $stories = $this->payload($this->client)['stories'];
        self::assertIsArray($stories);

        return array_values(array_map(static function (mixed $story): array {
            self::assertIsArray($story);

            /** @var array<string, mixed> $story */
            return $story;
        }, $stories));
    }

    /** @return list<mixed> */
    private function followedStoryIds(): array
    {
        return array_column($this->followedStories(), 'id');
    }

    /** @return list<mixed> */
    private function newCounts(): array
    {
        return array_column($this->followedStories(), 'newCount');
    }
}
