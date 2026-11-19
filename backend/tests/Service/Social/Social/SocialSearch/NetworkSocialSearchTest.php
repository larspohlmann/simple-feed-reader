<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\SocialSearch;

use App\Enum\SocialAccountStatus;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\PrivateFeedRule\LemmyPrivateFeedRule;
use App\Service\Social\Mastodon\MastodonPrivateFeedRule;
use App\Service\Social\Social\SearchSource\BlueskySearchSource;
use App\Service\Social\Social\SearchSource\LemmySearchSource;
use App\Service\Social\Mastodon\MastodonSearchSource;
use App\Service\Social\Social\SocialSearch\NetworkSocialSearch;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\TaggedItemOf;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NetworkSocialSearchTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string MASTODON_SEARCH = 'GET https://mastodon.example/api/v2/search'
        . '?type=statuses&resolve=false&limit=40&q=rss%20reader';
    private const string LEMMY_SEARCH = 'GET https://lemmy.example/api/v3/search'
        . '?q=rss%20reader&type_=Posts&sort=New&listing_type=All&limit=50';

    private ArrayAdapter $cache;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    public function testARefusedBlueskySessionMarksTheAccountForReconnecting(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::json([], Response::HTTP_UNAUTHORIZED)]);
        $account = SocialApiWiring::blueskyAccount();

        try {
            $this->search($http->client())->newest($account, 'rss reader');
            self::fail('A refused session must be a refused credential.');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(SocialAccountStatus::NeedsReconnect, $account->getStatus());
            self::assertSame([self::SESSION], $http->requestLines());
        }
    }

    public function testARefusedMastodonTokenMarksTheAccountForReconnecting(): void
    {
        $http = new RoutedHttpClient([self::MASTODON_SEARCH => self::json([], Response::HTTP_UNAUTHORIZED)]);
        $account = SocialApiWiring::mastodonAccount();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $this->entityManager = $entityManager;

        try {
            $this->search($http->client())->newest($account, 'rss reader');
            self::fail('A refused token must be a refused credential.');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(SocialAccountStatus::NeedsReconnect, $account->getStatus());
        }
    }

    public function testARefusedLaterAlternativeMarksTheAccountForReconnecting(): void
    {
        $rss = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
        $atom = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';
        $http = new RoutedHttpClient([
            $rss => self::json(['statuses' => [self::mastodonStatus('1')]]),
            $atom => self::json([], Response::HTTP_UNAUTHORIZED),
        ]);
        $account = SocialApiWiring::mastodonAccount();

        try {
            $this->search($http->client())->newest($account, '(rss || atom)');
            self::fail('A refused token must be a refused credential.');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(SocialAccountStatus::NeedsReconnect, $account->getStatus());
        }
    }

    public function testARefusedLemmyTokenMarksTheAccountForReconnecting(): void
    {
        $http = new RoutedHttpClient([self::LEMMY_SEARCH => self::json([], Response::HTTP_UNAUTHORIZED)]);
        $account = SocialApiWiring::lemmyAccount();

        try {
            $this->search($http->client())->newest($account, '"rss reader"');
            self::fail('A refused token must be a refused credential.');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(SocialAccountStatus::NeedsReconnect, $account->getStatus());
            self::assertSame([self::LEMMY_SEARCH], $http->requestLines());
        }
    }

    private function search(HttpClientInterface $http): NetworkSocialSearch
    {
        $searchAnswers = SocialApiWiring::searchAnswers($http, $this->cache);

        return new NetworkSocialSearch(
            new ActingAccountGuard($this->entityManager),
            new ServiceLocator([
                TaggedItemOf::index(BlueskySearchSource::class)
                    => fn (): BlueskySearchSource => new BlueskySearchSource(
                        SocialApiWiring::blueskySessions($http, $this->cache),
                    ),
                TaggedItemOf::index(MastodonSearchSource::class)
                    => static fn (): MastodonSearchSource => new MastodonSearchSource(
                        SocialApiWiring::mastodonAccountApi($http),
                        new MastodonPrivateFeedRule(),
                        $searchAnswers,
                    ),
                TaggedItemOf::index(LemmySearchSource::class)
                    => static fn (): LemmySearchSource => new LemmySearchSource(
                        SocialApiWiring::lemmyAccountApi($http),
                        SocialApiWiring::lemmyPostEntries(),
                        new LemmyPrivateFeedRule(),
                        $searchAnswers,
                    ),
            ]),
        );
    }

    /** @return array<string, mixed> */
    private static function mastodonStatus(string $id = '1', string $content = '<p>Which RSS reader?</p>'): array
    {
        return [
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => $content,
            'in_reply_to_id' => null,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
