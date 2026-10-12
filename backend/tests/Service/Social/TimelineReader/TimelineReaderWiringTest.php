<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineReader;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\TimelineReader\TimelineReaderInterface;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TimelineReaderWiringTest extends KernelTestCase
{
    use AssignsEntityIds;

    public function testTheContainerReadsAPageFromEachNetworkThroughItsOwnWiring(): void
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
        $reader = $container->get(TimelineReaderInterface::class);
        self::assertInstanceOf(TimelineReaderInterface::class, $reader);

        $mastodon = $reader->page($this->socialFeed(SocialNetwork::Mastodon));
        $bluesky = $reader->page($this->socialFeed(SocialNetwork::Bluesky));

        self::assertCount(0, $mastodon->parsed->entries);
        self::assertCount(0, $bluesky->parsed->entries);
        self::assertCount(3, $http->requests);
    }

    private function socialFeed(SocialNetwork $network): SocialFeed
    {
        $owner = SocialAccounts::owner();
        $cipher = new SocialCredentialCipher(self::getContainer()->get(InstanceSecretCipher::class));
        $account = SocialNetwork::Mastodon === $network
            ? new SocialAccount(
                $owner,
                $network,
                'alice@mastodon.example',
                '@alice@mastodon.example',
                'https://mastodon.example',
                $cipher->seal($owner->requireId(), SocialAccounts::ACCESS_TOKEN),
                '7890',
                new \DateTimeImmutable('2026-10-12 10:00:00')
            )
            : new SocialAccount(
                $owner,
                $network,
                'did:plc:alice',
                '@alice.bsky.social',
                'https://pds.example.test',
                $cipher->seal($owner->requireId(), SocialAccounts::APP_PASSWORD),
                'mnop',
                new \DateTimeImmutable('2026-10-12 10:00:00')
            );
        self::assignId($account, 11);

        return new SocialFeed($account, new Feed('social:x:11:home'), SocialFeedKind::Home, '');
    }
}
