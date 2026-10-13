<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineFetcher;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TimelineFetcherWiringTest extends KernelTestCase
{
    use AssignsEntityIds;

    public function testTheContainerReadsEachNetworksTimelineThroughItsOwnWiring(): void
    {
        self::bootKernel();
        $http = new RoutedHttpClient([
            'GET https://mastodon.example/api/v1/timelines/home?limit=40' => new MockResponse('[]'),
            'POST https://pds.example.test/xrpc/com.atproto.server.createSession' => new MockResponse(
                '{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}',
            ),
            'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50' => new MockResponse('{"feed":[]}'),
        ]);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $http->client());
        $container->set(
            NativeDnsResolver::class,
            new StaticDnsResolver(['mastodon.example' => ['93.184.216.34'], 'pds.example.test' => ['93.184.216.34']]),
        );
        $fetcher = $container->get(TimelineFetcherInterface::class);
        self::assertInstanceOf(TimelineFetcherInterface::class, $fetcher);
        $mastodon = $this->socialFeed(SocialNetwork::Mastodon, 1);
        $bluesky = $this->socialFeed(SocialNetwork::Bluesky, 2);

        $outcomes = [];
        foreach ($fetcher->fetchAll([1 => $mastodon, 2 => $bluesky]) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome::class;
        }

        self::assertSame([FetchedTimelineModel::class, FetchedTimelineModel::class], [$outcomes[1], $outcomes[2]]);
        self::assertCount(3, $http->requests);
    }

    public function testTimelinesAndFeedsAreReadOnTheOneRequestEngine(): void
    {
        $container = self::getContainer();
        $timelines = $container->get(TimelineFetcherInterface::class);
        $feeds = $container->get(BatchFeedFetcherInterface::class);
        self::assertInstanceOf(ConcurrentTimelineFetcher::class, $timelines);
        self::assertInstanceOf(ConcurrentFeedFetcher::class, $feeds);

        self::assertSame(
            self::engineOf(ConcurrentFeedFetcher::class, $feeds),
            self::engineOf(ConcurrentTimelineFetcher::class, $timelines),
        );
    }

    /** @param class-string $class */
    private static function engineOf(string $class, object $fetcher): ConcurrentRequestEngine
    {
        $engine = (new \ReflectionProperty($class, 'engine'))->getValue($fetcher);
        self::assertInstanceOf(ConcurrentRequestEngine::class, $engine);

        return $engine;
    }

    private function socialFeed(SocialNetwork $network, int $id): SocialFeed
    {
        $owner = SocialAccounts::owner();
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        self::assertInstanceOf(InstanceSecretCipher::class, $instanceCipher);
        $cipher = new SocialCredentialCipher($instanceCipher);
        $account = SocialNetwork::Mastodon === $network
            ? new SocialAccount(
                $owner,
                $network,
                'alice@mastodon.example',
                '@alice@mastodon.example',
                'https://mastodon.example',
                $cipher->seal($owner->requireId(), SocialAccounts::ACCESS_TOKEN),
                '7890',
                new \DateTimeImmutable('2026-10-12 10:00:00'),
            )
            : new SocialAccount(
                $owner,
                $network,
                'did:plc:alice',
                '@alice.bsky.social',
                'https://pds.example.test',
                $cipher->seal($owner->requireId(), SocialAccounts::APP_PASSWORD),
                'mnop',
                new \DateTimeImmutable('2026-10-12 10:00:00'),
            );
        self::assignId($account, 10 + $id);

        return new SocialFeed(
            $account,
            self::withId(new Feed('social:x:' . $id . ':home'), $id),
            SocialFeedKind::Home,
            '',
        );
    }
}
