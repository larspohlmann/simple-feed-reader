<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\ShareKind;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\SocialFeedRefresher;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Model\PrivateFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\Bluesky;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StaticDnsResolver;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The container's own reader, mapper and store, platform rules included, from a getTimeline answer to the rows. */
final class SocialFeedRefresherBlueskyTest extends DbTestCase
{
    private const string PDS = 'https://pds.example.test';
    private const string IMAGE_POST = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mv3shqdfuc2e';
    private const string FACET_POST = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3facetonly';
    private const string LINK_POST = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';

    private RoutedHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new RoutedHttpClient([
            'POST ' . self::PDS . '/xrpc/com.atproto.server.createSession' => new MockResponse(
                '{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}',
            ),
            'GET ' . self::PDS . '/xrpc/app.bsky.feed.getTimeline?limit=50' => new MockResponse(self::timeline()),
        ]);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $this->http->client());
        $container->set(NativeDnsResolver::class, new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]));
    }

    public function testATimelinePageIsStoredAsTheRssPathWouldStoreItsPosts(): void
    {
        $home = $this->blueskyHome();

        $pass = $this->refreshed($home);

        self::assertSame(1, $pass->tally->fetched());
        self::assertSame(3, $pass->tally->entriesCreated());
        $repost = $this->entry(self::IMAGE_POST);
        self::assertSame('', $repost->getTitle());
        self::assertTrue($repost->isTitleDerived());
        self::assertStringContainsString('Reposted by', $repost->getContentHtml() ?? '');
        $link = $this->entry(self::LINK_POST);
        self::assertSame(ShareKind::Link, $link->getShareKind());
        $cards = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$link->requireId()]);
        self::assertSame(Bluesky::CARD, ($cards[$link->requireId()] ?? [])[0]->url ?? null);
        self::assertSame(1, $this->entityManager->getRepository(ShareEnrichment::class)->count());
        self::assertSame([self::PDS . '/xrpc/app.bsky.feed.getTimeline?limit=50'], $this->timelineRequests());
    }

    public function testATimelinePostWithALinkFacetAndNoEmbedIsAShareAwaitingItsCard(): void
    {
        $home = $this->blueskyHome();

        $this->refreshed($home);

        $post = $this->entry(self::FACET_POST);
        self::assertSame(ShareKind::Link, $post->getShareKind());
        $rows = $this->entityManager->getRepository(ShareEnrichment::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(ShareEnrichmentKind::LinkCards, $rows[0]->getKind());
        self::assertSame($post->requireId(), $rows[0]->getEntry()->requireId());
    }

    /** @return array{record: array<string, mixed>, embed?: array<string, mixed>, uri?: string} */
    private static function linkOnlyPost(): array
    {
        $url = 'https://github.com/webx-ui/module-blog';
        $post = self::postView('external');
        unset($post['embed']);
        $post['uri'] = self::FACET_POST;
        $post['record']['text'] = 'module-blog v0.69.0 ' . $url;
        $post['record']['facets'] = [[
            'index' => ['byteStart' => 20, 'byteEnd' => 20 + \strlen($url)],
            'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
        ]];

        return $post;
    }

    private function blueskyHome(): SocialFeed
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new UserFactory($this->entityManager, $hasher))->create('social-bluesky-refresh@example.test');
        $cipher = self::getContainer()->get(InstanceSecretCipher::class);
        self::assertInstanceOf(InstanceSecretCipher::class, $cipher);
        $account = new SocialAccount(
            $owner,
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            self::PDS,
            (new SocialCredentialCipher($cipher))->seal($owner->requireId(), SocialAccounts::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $clock)
            ->create($account, PrivateFeedModel::home());
        $home = SocialFeeds::repository($this->entityManager)->findOneFor($account, SocialFeedKind::Home, '');
        self::assertNotNull($home);

        return $home;
    }

    private function refreshed(SocialFeed $socialFeed): RefreshPass
    {
        $clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        $pass = new RefreshPass([$socialFeed->getFeed()], $clock, $clock->now()->getTimestamp() + 300);
        $this->refresher()->refreshAll($pass, $clock->now());

        return $pass;
    }

    private function refresher(): SocialFeedRefresher
    {
        $refresher = self::getContainer()->get(SocialFeedRefresher::class);
        self::assertInstanceOf(SocialFeedRefresher::class, $refresher);

        return $refresher;
    }

    private static function timeline(): string
    {
        $imageOnly = self::postView('images');
        $imageOnly['record']['text'] = '';
        $repost = [
            'post' => $imageOnly,
            'reason' => [
                '$type' => 'app.bsky.feed.defs#reasonRepost',
                'by' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social', 'displayName' => 'Bob'],
                'indexedAt' => '2026-10-12T11:00:00.000Z',
            ],
        ];

        $posts = [$repost, ['post' => self::postView('external')], ['post' => self::linkOnlyPost()]];

        return json_encode(['feed' => $posts], \JSON_THROW_ON_ERROR);
    }

    /** @return array{record: array<string, mixed>, embed?: array<string, mixed>, uri?: string} */
    private static function postView(string $fixture): array
    {
        /** @var array{posts: list<array{record: array<string, mixed>}>} $answer */
        $answer = json_decode(Bluesky::fixture($fixture), true, flags: \JSON_THROW_ON_ERROR);

        return $answer['posts'][0];
    }

    private function entry(string $guid): Entry
    {
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => $guid]);
        self::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    /** @return list<string> */
    private function timelineRequests(): array
    {
        return array_values(array_filter(
            $this->http->requestedUrls(),
            static fn (string $url): bool => !str_contains($url, 'createSession'),
        ));
    }
}
