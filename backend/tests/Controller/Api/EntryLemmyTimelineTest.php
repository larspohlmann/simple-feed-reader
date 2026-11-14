<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\SocialFeedKind;
use App\Entity\Subscription;
use App\Service\Ingestion\Refresh\Model\RefreshRequestModel;
use App\Service\Outbound\Fetch\Model\FetchResponseModel;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A Lemmy timeline read by a refresh round, then listed: its posts are Lemmy threads the reader votes on. */
final class EntryLemmyTimelineTest extends ApiTestCase
{
    private const string SUBSCRIBED
        = 'GET https://lemmy.example/api/v3/post/list?type_=Subscribed&sort=New&limit=50';
    private const string POST_LIST = __DIR__ . '/../../Fixtures/lemmy/post-list.json';
    private const string INSTANCE = 'https://lemmy.example';

    public function testALemmyEntryCarriesItsVote(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'lemmy-timeline@example.test');
        $entityManager = $this->entityManager();
        $clock = new MockClock('2026-11-14 12:00:00', 'UTC');
        $account = SocialAccounts::lemmyOf($user);
        $entityManager->persist($account);
        $entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $entityManager, $clock)
            ->create($account, PrivateFeedModel::timeline(SocialFeedKind::Home));
        $posts = new MockResponse((string) file_get_contents(self::POST_LIST));
        $http = new RoutedHttpClient([self::SUBSCRIBED => $posts]);
        RefreshRunners::fromContainer(self::getContainer(), $entityManager, $clock)
            ->build(SocialApiWiring::passFetcher($http->client(), new ArrayAdapter()), self::homepage())
            ->run(RefreshRequestModel::allDue(300));
        $subscription = $entityManager->getRepository(Subscription::class)->findOneBy(['user' => $user]);
        $subscriptionId = $subscription?->getId();
        self::assertNotNull($subscriptionId);

        $client->request('GET', '/api/entries?subscription=' . $subscriptionId);

        $entries = $this->payload($client)['entries'];
        self::assertIsArray($entries);
        self::assertCount(3, $entries);
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertNotNull($entry['myVote']);
            self::assertNull($entry['social']);
        }
    }

    private static function homepage(): StubFeedFetcher
    {
        $homepages = new StubFeedFetcher();
        $homepages->willReturn(
            self::INSTANCE,
            FetchResponseModel::fetched(self::INSTANCE, false, '<html lang="en"></html>', null, null),
        );

        return $homepages;
    }
}
