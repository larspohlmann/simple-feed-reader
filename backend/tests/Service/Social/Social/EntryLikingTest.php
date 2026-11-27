<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryStateRepository;
use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Outbound\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Social\EntryLiking;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StaticDnsResolver;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EntryLikingTest extends DbTestCase
{
    use SeedsUsers;

    private const string POST_URL = 'https://mastodon.social/@Gargron/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string UNFAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/unfavourite';

    private RoutedHttpClient $http;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new RoutedHttpClient([
            self::SEARCH => new MockResponse('{"statuses":[{"id":"110","favourites_count":5}]}'),
            self::UNFAVOURITE => new MockResponse('{"id":"110"}'),
        ]);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $this->http->client());
        $container->set(NativeDnsResolver::class, new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]));
        $this->reader = $this->user('entry-liking@example.test');
    }

    public function testAPostWithoutAnAccountOnItsNetworkIsLikedLocally(): void
    {
        $row = $this->rowOf($this->persistMastodonPost());

        self::assertNull($this->liking()->like($this->reader, $row));

        self::assertTrue($this->requireState($row)->isLiked());
        self::assertSame([], $this->http->requests);
    }

    public function testALocalLikeSurvivesConnectingTheNetwork(): void
    {
        $row = $this->rowOf($this->persistMastodonPost());
        $this->liking()->like($this->reader, $row);

        $this->connectMastodon();

        self::assertTrue($this->requireState($row)->isLiked());
        self::assertTrue($this->rowOf($row->entry)->marks->isLiked);
        self::assertSame([], $this->http->requests);
    }

    public function testANetworkUnlikeAlsoClearsALocalLike(): void
    {
        $entry = $this->persistMastodonPost();
        $this->liking()->like($this->reader, $this->rowOf($entry));
        $this->storeNetworkLike($entry, $this->connectMastodon());

        $this->liking()->unlike($this->reader, $this->rowOf($entry));

        self::assertContains(self::UNFAVOURITE, $this->routesAsked());
        self::assertFalse($this->requireState($this->rowOf($entry))->isLiked());
        self::assertSame(0, $this->entityManager->getRepository(SocialLike::class)->count([]));
    }

    public function testAnUnlikeWithoutANetworkTakesTheLocalLikeBack(): void
    {
        $row = $this->rowOf($this->persistMastodonPost());
        $this->liking()->like($this->reader, $row);

        self::assertNull($this->liking()->unlike($this->reader, $row));

        self::assertFalse($this->requireState($row)->isLiked());
        self::assertSame([], $this->http->requests);
    }

    public function testAnOrdinaryArticleIsLikedLocallyWhateverAccountsTheReaderHolds(): void
    {
        $this->connectMastodon();
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $row = $this->rowOf($fixtures->persistPost(
            $fixtures->subscribe($this->reader, NetworkFeedSubscriptions::PLAIN),
            self::POST_URL,
            '2026-11-26T10:00:00Z',
        ));

        self::assertNull($this->liking()->like($this->reader, $row));

        self::assertTrue($this->requireState($row)->isLiked());
        self::assertSame([], $this->http->requests);
    }

    private function liking(): EntryLiking
    {
        /** @var EntryLiking $liking */
        $liking = self::getContainer()->get(EntryLiking::class);

        return $liking;
    }

    private function persistMastodonPost(): Entry
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);

        return $fixtures->persistPost(
            $fixtures->subscribe($this->reader, NetworkFeedSubscriptions::MASTODON_PROFILE),
            self::POST_URL,
            '2026-11-26T10:00:00Z',
        );
    }

    private function connectMastodon(): SocialAccount
    {
        /** @var InstanceSecretCipher $instanceCipher */
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        $credential = (new SocialCredentialCipher($instanceCipher))->seal(
            $this->reader->requireId(),
            SocialAccounts::ACCESS_TOKEN,
        );

        return SocialFeeds::persistedAccount($this->entityManager, new SocialAccount(
            $this->reader,
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
            $credential,
            '7890',
            new \DateTimeImmutable('2026-11-26 10:00:00'),
        ));
    }

    private function storeNetworkLike(Entry $entry, SocialAccount $account): void
    {
        $item = $entry->getCanonicalItem();
        self::assertNotNull($item);
        $likedAt = new \DateTimeImmutable('2026-11-26 11:00:00');
        $this->entityManager->persist(new SocialLike($this->reader, $item, $account, '110', $likedAt));
        $this->entityManager->flush();
    }

    private function rowOf(Entry $entry): EntryListRow
    {
        /** @var EntryListRepository $entryList */
        $entryList = self::getContainer()->get(EntryListRepository::class);

        return $entryList->getRowForUser($this->reader->requireId(), $entry->requireId());
    }

    private function requireState(EntryListRow $row): EntryState
    {
        /** @var EntryStateRepository $states */
        $states = self::getContainer()->get(EntryStateRepository::class);
        $this->entityManager->clear();
        $state = $states->findOneForUserEntry($this->reader->requireId(), $row->entry->requireId());
        self::assertNotNull($state);

        return $state;
    }

    /** @return list<string> */
    private function routesAsked(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->http->requests,
        );
    }
}
