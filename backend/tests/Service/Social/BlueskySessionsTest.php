<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySessionsTest extends TestCase
{
    use AssignsEntityIds;

    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';

    public function testASecondPollReusesTheCachedSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-1')]);
        $sessions = $this->sessions($http, new ArrayAdapter());
        $account = self::account();

        $first = $sessions->current($account);
        $second = $sessions->current($account);

        self::assertSame('jwt-1', $first->accessJwt);
        self::assertEquals($first, $second);
        self::assertCount(1, $http->requests);
    }

    public function testAFreshSessionReplacesTheCachedOne(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social', 'https://pds.example.test'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-2')]);
        $sessions = $this->sessions($http, $cache);

        self::assertSame('stale-jwt', $sessions->current($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->fresh($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->current($account)->accessJwt);
        self::assertCount(1, $http->requests);
    }

    public function testAFailedRefreshLeavesNoStaleSessionBehind(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social', 'https://pds.example.test'),
        );
        $http = new RoutedHttpClient([self::SESSION => new MockResponse('', [
            'http_code' => Response::HTTP_SERVICE_UNAVAILABLE,
        ])]);

        try {
            $this->sessions($http, $cache)->fresh($account);
            self::fail('An outage must not open a session.');
        } catch (SocialNetworkUnreachableException) {
            self::assertNull(self::cacheOver($cache)->find($account));
        }
    }

    public function testTheCacheHoldsTheTokenSealedNeverInTheClear(): void
    {
        $cache = new ArrayAdapter();
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-secret-token')]);

        $this->sessions($http, $cache)->current(self::account());

        self::assertNotSame([], $cache->getValues());
        self::assertStringNotContainsString('jwt-secret-token', serialize($cache->getValues()));
    }

    public function testANewCredentialMissesTheSessionTheOldOneOpened(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('old-jwt', 'alice.bsky.social', 'https://pds.example.test'),
        );
        $account->replaceCredential(
            '@alice.bsky.social',
            SocialAccounts::cipher()->seal(SocialAccounts::OWNER_ID, 'wxyz-wxyz-wxyz-9999'),
            '9999',
            new \DateTimeImmutable('2026-10-12 11:00:00'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-new')]);

        self::assertSame('jwt-new', $this->sessions($http, $cache)->current($account)->accessJwt);
    }

    private function sessions(RoutedHttpClient $http, ArrayAdapter $cache): BlueskySessions
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());

        return new BlueskySessions(
            new BlueskySessionOpener(
                new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
                new StoredCredential(SocialAccounts::cipher()),
            ),
            self::cacheOver($cache),
        );
    }

    private static function cacheOver(ArrayAdapter $cache): BlueskySessionCache
    {
        return new BlueskySessionCache($cache, new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))));
    }

    private static function account(): SocialAccount
    {
        $account = SocialAccounts::bluesky();
        self::assignId($account, 3);

        return $account;
    }

    private static function sessionAnswer(string $accessJwt): MockResponse
    {
        return new MockResponse(json_encode(
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
