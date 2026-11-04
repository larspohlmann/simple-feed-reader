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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class SubscriptionTrendingCountsTest extends ApiTestCase
{
    /** @return iterable<string, array{string}> */
    public static function sidebarEndpoints(): iterable
    {
        yield 'the counts poll' => ['/api/subscriptions/counts'];
        yield 'the sidebar list' => ['/api/subscriptions'];
    }

    #[DataProvider('sidebarEndpoints')]
    public function testCarriesTheUnopenedStoriesOfTheDayWhenNoWindowIsAsked(string $endpoint): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('trending-counts-day@example.com');
        $this->seedStories($user);

        $client->request('GET', $endpoint, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['window' => 'day', 'unopened' => 2], $this->payload($client)['trending']);
    }

    #[DataProvider('sidebarEndpoints')]
    public function testCarriesTheUnopenedStoriesOfTheAskedWindow(string $endpoint): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('trending-counts-week@example.com');
        $this->seedStories($user);

        $client->request('GET', $endpoint . '?trendingWindow=week', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['window' => 'week', 'unopened' => 1], $this->payload($client)['trending']);
    }

    #[DataProvider('sidebarEndpoints')]
    public function testReportsNoUnopenedStoryWhenTheReaderSwitchedTrendingOff(string $endpoint): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('trending-counts-off@example.com');
        $this->seedStories($user);
        $this->switchTrendingOff($user);

        $client->request('GET', $endpoint . '?trendingWindow=week', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['window' => 'week', 'unopened' => 0], $this->payload($client)['trending']);
    }

    #[DataProvider('sidebarEndpoints')]
    public function testAnUnknownWindowIsAValidationProblem(string $endpoint): void
    {
        $client = self::createClient();
        [$headers] = $this->bearerAndUser('trending-counts-unknown@example.com');

        $client->request('GET', $endpoint . '?trendingWindow=month', server: $headers);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $errors = $this->payload($client)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey('trendingWindow', $errors);
    }

    private function switchTrendingOff(User $user): void
    {
        $user->getPreferences()->setTrendingEnabled(false);
        $this->entityManager()->flush();
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
