<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\PassFetcher;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface;
use App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\ConcurrentPassFetcher;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PassFetcherWiringTest extends KernelTestCase
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
        $fetcher = $container->get(PassFetcherInterface::class);
        self::assertInstanceOf(PassFetcherInterface::class, $fetcher);
        $mastodon = $this->socialFeed(SocialNetwork::Mastodon, 1);
        $bluesky = $this->socialFeed(SocialNetwork::Bluesky, 2);
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $pass = new RefreshPass(
            [$mastodon->getFeed(), $bluesky->getFeed()],
            $clock,
            $clock->now()->getTimestamp() + 300,
        );

        $outcomes = [];
        foreach ($fetcher->fetchAll($pass, [1 => $mastodon, 2 => $bluesky]) as $feedId => $outcome) {
            self::assertInstanceOf(TimelinePassOutcomeModel::class, $outcome);
            $outcomes[$feedId] = $outcome->outcome::class;
        }

        self::assertSame([FetchedTimelineModel::class, FetchedTimelineModel::class], [$outcomes[1], $outcomes[2]]);
        self::assertCount(3, $http->requests);
    }

    public function testARefreshAndASingleFeedFetchUseTheOneRequestEngine(): void
    {
        $container = self::getContainer();
        $passes = $container->get(PassFetcherInterface::class);
        $feeds = $container->get(BatchFeedFetcherInterface::class);
        self::assertInstanceOf(ConcurrentPassFetcher::class, $passes);
        self::assertInstanceOf(ConcurrentFeedFetcher::class, $feeds);

        self::assertSame(
            self::engineOf(ConcurrentFeedFetcher::class, $feeds),
            self::engineOf(ConcurrentPassFetcher::class, $passes),
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

        $feed = self::withId(new Feed('social:x:' . $id . ':home'), $id);
        $feed->setSourceFormat(
            SocialNetwork::Mastodon === $network ? SourceFormat::MASTODON_API : SourceFormat::BLUESKY_API,
        );

        return new SocialFeed($account, $feed, SocialFeedKind::Home, '');
    }
}
