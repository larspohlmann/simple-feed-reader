<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;

final class SubscriptionTrendingCountsTest extends ApiTestCase
{
    public function testTheCountsPollCarriesTheUnopenedTrendingStoriesPerWindow(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('trending-counts@example.com');
        $this->seedStories($user);

        $client->request('GET', '/api/subscriptions/counts', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['day' => 2, 'week' => 1], $this->payload($client)['trending']);
    }

    public function testTheSidebarListCarriesTheSameCounts(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('trending-list@example.com');
        $this->seedStories($user);

        $client->request('GET', '/api/subscriptions', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['day' => 2, 'week' => 1], $this->payload($client)['trending']);
    }

    private function seedStories(User $user): void
    {
        $fixtures = new TrendingFixtures($this->entityManager());
        $feeds = array_map(
            static fn (string $name): Feed => $fixtures->feed($name),
            ['alpha', 'beta', 'gamma', 'delta', 'omega'],
        );
        $fixtures->subscribe($user, ...$feeds);

        $this->story($fixtures, TrendingWindow::Day, \array_slice($feeds, 0, 3));
        $this->story($fixtures, TrendingWindow::Day, \array_slice($feeds, 1, 3));
        $opened = $this->story($fixtures, TrendingWindow::Day, \array_slice($feeds, 2, 3));
        $this->story($fixtures, TrendingWindow::Day, \array_slice($feeds, 0, 2));
        $this->story($fixtures, TrendingWindow::Week, $feeds);
        $this->markViewed($user, $opened);
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return Entry the story's first entry
     */
    private function story(TrendingFixtures $fixtures, TrendingWindow $window, array $feeds): Entry
    {
        $members = array_map(
            static fn (Feed $feed): Entry => $fixtures->entry($feed, 'Story', '2031-05-14 09:00:00'),
            $feeds,
        );
        $fixtures->story($window, '2031-05-14 10:00:00', ...$members);

        return $members[0];
    }

    private function markViewed(User $user, Entry $entry): void
    {
        $state = new EntryState($user, $entry);
        $state->markViewed(new \DateTimeImmutable('2031-05-14 11:00:00'));
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();
    }
}
