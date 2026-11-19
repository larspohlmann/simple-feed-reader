<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ImportRule;

use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\BlueskyApiClient;
use App\Service\Social\Social\BlueskyIdentityResolver;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\HiddenImportFollows;
use App\Service\Social\Social\ImportRule\BlueskyImportRule;
use App\Service\Social\Social\Model\HiddenProfileModel;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SocialImportFeedModel;
use App\Service\Social\Social\TolerantHiddenProfiles;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BlueskyImportRuleTest extends TestCase
{
    private const string RESOLVE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=';

    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->logger = new RecordingLogger();
    }

    public function testEachHiddenFollowComesInAsAnAuthorFeedKeyedByTheChosenIdentity(): void
    {
        $this->hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'), 'rusty-17.bsky.social');

        $accountOnly = $this->rule(new RoutedHttpClient([]))->findAccountOnlyFeeds(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('Rusty-17.bsky.social', 'Rusty'),
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
        ]);

        self::assertEquals(
            ['Rusty-17.bsky.social' => PrivateFeedModel::author('did:plc:rusty', 'Rusty')],
            $accountOnly,
        );
    }

    public function testNoFollowIsAccountOnlyWhenTheAppViewCannotSayAndThatIsLogged(): void
    {
        $this->hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $this->hiddenProfiles->failure = new SocialNetworkUnreachableException('down');

        $accountOnly = $this->rule(new RoutedHttpClient([]))->findAccountOnlyFeeds(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', 'Rusty'),
        ]);

        self::assertSame([], $accountOnly);
        self::assertSame(
            'Import could not tell which Bluesky follows are hidden; all come in by RSS.',
            $this->logger->records[0]['message'] ?? null,
        );
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function feedUrls(): iterable
    {
        yield 'a profile rss feed by DID' => ['https://bsky.app/profile/did:plc:bob/rss', 'did:plc:bob'];
        yield 'a profile rss feed by handle' => ['https://bsky.app/profile/bob.bsky.social/rss', 'bob.bsky.social'];
        yield 'an author feed' => ['social:bluesky:3:author:did:plc:rusty', 'did:plc:rusty'];
        yield 'a home timeline' => ['social:bluesky:3:home', null];
        yield 'a Mastodon profile feed' => ['https://mastodon.example/@bob.rss', null];
        yield 'an ordinary feed' => ['https://example.com/feed.xml', null];
    }

    #[DataProvider('feedUrls')]
    public function testAFeedFollowsThePersonItsAddressNames(string $feedUrl, ?string $identity): void
    {
        self::assertSame($identity, $this->rule(new RoutedHttpClient([]))->parseFollowedIdentity($feedUrl));
    }

    public function testADidIsResolvedAndAHandleIsNot(): void
    {
        $rule = $this->rule(new RoutedHttpClient([]));

        self::assertTrue($rule->isResolvedIdentity('did:plc:abc'));
        self::assertFalse($rule->isResolvedIdentity('alice.bsky.social'));
    }

    public function testAHandleIsResolvedToItsDid(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'alice.bsky.social' => new MockResponse('{"did":"did:plc:alice"}'),
        ]);

        self::assertSame('did:plc:alice', $this->rule($http)->fetchResolvedIdentity('alice.bsky.social'));
    }

    public function testTheFollowsAvatarsMayBeCached(): void
    {
        self::assertTrue($this->rule(new RoutedHttpClient([]))->allowsFollowAvatarCache());
    }

    private function rule(RoutedHttpClient $http): BlueskyImportRule
    {
        $guard = new UrlGuard(new StaticDnsResolver(['public.api.bsky.app' => ['93.184.216.34']]), new IpValidator());

        return new BlueskyImportRule(
            new HiddenImportFollows(new TolerantHiddenProfiles($this->hiddenProfiles, $this->logger)),
            new BlueskyIdentityResolver(new BlueskyApiClient(FetchWiring::patientApiClient($http->client(), $guard))),
        );
    }
}
