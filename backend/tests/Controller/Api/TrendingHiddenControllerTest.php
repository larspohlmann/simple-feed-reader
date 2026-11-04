<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testHidingALeadTakesItsWholeStoryOffTheListAndTheBadge(): void
    {
        $reader = $this->signIn($this->client, 'trending-hide@example.com');
        $feeds = $this->followedFeeds($reader);
        $hidden = $this->story($feeds);
        $kept = $this->story(\array_slice($feeds, 0, 3));

        $this->hide('day', $hidden[0]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([$kept[0]->requireId()], $this->leadIdsOfTheDay());
        $this->client->request('GET', '/api/subscriptions/counts');
        self::assertSame(['day' => 1, 'week' => 0], $this->payload($this->client)['trending']);
        self::assertSame(self::idsOf($hidden), $this->hiddenEntryIdsOf($reader));
    }

    public function testHidingAgainAnswersTheSameAndLeavesOtherReadersAlone(): void
    {
        $reader = $this->signIn($this->client, 'trending-hide-twice@example.com');
        $feeds = $this->followedFeeds($reader);
        $other = $this->factory()->create('trending-hide-other@example.com');
        $this->fixtures->subscribe($other, ...$feeds);
        $hidden = $this->story($feeds);

        $this->hide('day', $hidden[0]);
        $this->hide('day', $hidden[0]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->authenticate($this->client, $other);
        self::assertSame([$hidden[0]->requireId()], $this->leadIdsOfTheDay());
    }

    public function testAnEntryInNoStoryOfTheWindowTheReaderSeesIsNotFound(): void
    {
        $reader = $this->signIn($this->client, 'trending-hide-missing@example.com');
        $story = $this->story([...$this->followedFeeds($reader), $this->fixtures->feed('stranger')]);

        $this->hide('week', $story[0]);

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        self::assertSame('trending_story_not_found', $this->payload($this->client)['type']);

        $this->hide('day', $story[4]);

        $this->assertRejected($this->client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->hiddenEntryIdsOf($reader));
    }

    public function testAnUnknownWindowIsRefused(): void
    {
        $reader = $this->signIn($this->client, 'trending-hide-window@example.com');
        $story = $this->story($this->followedFeeds($reader));

        $this->hide('month', $story[0]);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $errors = $this->payload($this->client)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey('window', $errors);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('malformedBodies')]
    public function testAMalformedBodyIsRefusedAndHidesNothing(array $body, string $field): void
    {
        $reader = $this->signIn($this->client, 'trending-hide-malformed@example.com');
        $this->story($this->followedFeeds($reader));

        $this->client->request(
            'POST',
            '/api/trending/hidden',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->hiddenEntryIdsOf($reader));
        $errors = $this->payload($this->client)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey($field, $errors);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedBodies(): iterable
    {
        yield 'no entryId' => [['window' => 'day'], 'entryId'];
        yield 'a text entryId' => [['window' => 'day', 'entryId' => 'seven'], 'entryId'];
        yield 'entryId 0' => [['window' => 'day', 'entryId' => 0], 'entryId'];
        yield 'no window' => [['entryId' => 1], 'window'];
        yield 'a blank window' => [['window' => '', 'entryId' => 1], 'window'];
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
     * One entry per feed, newest first, so the first article leads.
     *
     * @param list<Feed> $feeds
     *
     * @return list<Entry>
     */
    private function story(array $feeds): array
    {
        $members = [];
        foreach ($feeds as $position => $feed) {
            $members[] = $this->fixtures->entry($feed, 'Story', \sprintf('2031-05-14 %02d:00:00', 10 - $position));
        }
        $this->fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...$members);

        return $members;
    }

    private function hide(string $window, Entry $entry): void
    {
        $this->client->request(
            'POST',
            '/api/trending/hidden',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['window' => $window, 'entryId' => $entry->requireId()], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<int> */
    private function leadIdsOfTheDay(): array
    {
        $this->client->request('GET', '/api/trending?window=day');
        self::assertResponseIsSuccessful();
        $stories = $this->payload($this->client)['stories'];
        self::assertIsArray($stories);

        $leadIds = [];
        foreach ($stories as $story) {
            self::assertIsArray($story);
            self::assertIsArray($story['lead']);
            self::assertIsInt($story['lead']['id']);
            $leadIds[] = $story['lead']['id'];
        }

        return $leadIds;
    }

    /** @return list<int> */
    private function hiddenEntryIdsOf(User $reader): array
    {
        /** @var list<int|string> $entryIds */
        $entryIds = $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM trending_hidden_entry WHERE user_id = ? ORDER BY entry_id',
            [$reader->requireId()],
        );

        return array_map(static fn (int|string $entryId): int => (int) $entryId, $entryIds);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int> ascending
     */
    private static function idsOf(array $entries): array
    {
        $entryIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);
        sort($entryIds);

        return $entryIds;
    }
}
