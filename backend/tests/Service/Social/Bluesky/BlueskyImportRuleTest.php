<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Service\Social\Bluesky\BlueskyImportRule;
use App\Service\Social\Bluesky\HiddenImportFollows;
use App\Service\Social\Bluesky\Model\HiddenProfileModel;
use App\Service\Social\Bluesky\TolerantHiddenProfiles;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SocialImportFeedModel;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
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
        return new BlueskyImportRule(
            new HiddenImportFollows(new TolerantHiddenProfiles($this->hiddenProfiles, $this->logger)),
            SocialApiWiring::blueskyIdentityResolver($http->client()),
        );
    }
}
