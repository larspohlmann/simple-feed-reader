<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\TrendingStory;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class TrendingHiddenControllerTest extends ApiTestCase
{
    private KernelBrowser $client;
    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->fixtures = new TrendingFixtures($this->entityManager());
    }

    public function testHidingAStoryTakesItOffTheListAndTheBadge(): void
    {
        $reader = $this->signInAsOwner('trending-hide@example.com');
        $feeds = $this->followedFeeds($reader);
        $hidden = $this->story($feeds);
        $kept = $this->story(\array_slice($feeds, 0, 3));

        $this->hide($hidden->requireId());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([$kept->requireId()], $this->storyIdsOfTheDay());
        $this->client->request('GET', '/api/subscriptions/counts');
        self::assertSame(['window' => 'day', 'unopened' => 1], $this->payload($this->client)['trending']);
        self::assertSame([$hidden->requireId()], $this->hiddenStoryIdsOf($reader));
    }

    public function testHidingAgainAnswersTheSameAndLeavesOtherReadersAlone(): void
    {
        $reader = $this->signInAsOwner('trending-hide-twice@example.com');
        $feeds = $this->followedFeeds($reader);
        $other = $this->factory()->create('trending-hide-other@example.com');
        $this->fixtures->subscribe($other, ...$feeds);
        $hidden = $this->story($feeds);
        $othersStory = $this->fixtures->storyOf($other, TrendingWindow::Day, '2031-05-14 10:00:00', ...array_map(
            fn (Feed $feed): Entry => $this->fixtures->entry($feed, 'Story', '2031-05-14 09:00:00'),
            $feeds,
        ));

        $this->hide($hidden->requireId());
        $this->hide($hidden->requireId());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([$hidden->requireId()], $this->hiddenStoryIdsOf($reader));
        $this->authenticate($this->client, $other);
        self::assertSame([$othersStory->requireId()], $this->storyIdsOfTheDay());
    }

    public function testAnUnknownStoryIsNotFound(): void
    {
        $reader = $this->signInAsOwner('trending-hide-missing@example.com');
        $story = $this->story($this->followedFeeds($reader));

        $this->hide($story->requireId() + 1);

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        self::assertSame('trending_story_not_found', $this->payload($this->client)['type']);
        self::assertSame([], $this->hiddenStoryIdsOf($reader));
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('malformedBodies')]
    public function testAMalformedBodyIsRefusedAndHidesNothing(array $body, string $field): void
    {
        $reader = $this->signInAsOwner('trending-hide-malformed@example.com');
        $this->story($this->followedFeeds($reader));

        $this->client->request(
            'POST',
            '/api/trending/hidden',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->hiddenStoryIdsOf($reader));
        $errors = $this->payload($this->client)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey($field, $errors);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedBodies(): iterable
    {
        yield 'no storyId' => [[], 'storyId'];
        yield 'a text storyId' => [['storyId' => 'x'], 'storyId'];
        yield 'storyId 0' => [['storyId' => 0], 'storyId'];
    }

    /** @return list<Feed> three article feeds and a social account, all followed by the reader */
    private function followedFeeds(User $reader): array
    {
        $feeds = [
            $this->fixtures->feed('alpha'),
            $this->fixtures->feed('beta'),
            $this->fixtures->feed('gamma'),
            $this->fixtures->feed('ann', SocialNetwork::Bluesky),
        ];
        $this->fixtures->subscribe($reader, ...$feeds);

        return $feeds;
    }

    /**
     * One entry per feed, newest first.
     *
     * @param list<Feed> $feeds
     */
    private function story(array $feeds): TrendingStory
    {
        $members = [];
        foreach ($feeds as $position => $feed) {
            $members[] = $this->fixtures->entry($feed, 'Story', \sprintf('2031-05-14 %02d:00:00', 10 - $position));
        }

        return $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...$members);
    }

    private function hide(int $storyId): void
    {
        $this->client->request(
            'POST',
            '/api/trending/hidden',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['storyId' => $storyId], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<int> */
    private function storyIdsOfTheDay(): array
    {
        $this->client->request('GET', '/api/trending?window=day');
        self::assertResponseIsSuccessful();
        $stories = $this->payload($this->client)['stories'];
        self::assertIsArray($stories);

        $storyIds = [];
        foreach ($stories as $story) {
            self::assertIsArray($story);
            self::assertIsArray($story['story']);
            self::assertIsInt($story['story']['id']);
            $storyIds[] = $story['story']['id'];
        }

        return $storyIds;
    }

    private function signInAsOwner(string $email): User
    {
        $reader = $this->signIn($this->client, $email);
        $this->fixtures = $this->fixtures->ownedBy($reader);

        return $reader;
    }

    /** @return list<int> */
    private function hiddenStoryIdsOf(User $reader): array
    {
        /** @var list<int|string> $storyIds */
        $storyIds = $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT story_id FROM trending_story_hide WHERE user_id = ? ORDER BY story_id',
            [$reader->requireId()],
        );

        return array_map(static fn (int|string $storyId): int => (int) $storyId, $storyIds);
    }
}
