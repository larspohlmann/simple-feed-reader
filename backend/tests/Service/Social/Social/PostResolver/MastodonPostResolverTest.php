<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostResolver;

use App\Entity\LinkCardContent;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\PostPreviewModel;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostResolverTest extends TestCase
{
    private const string OUTSIDE_SCOPES = 'This action is outside the authorized scopes';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';

    public function testAPostResolvesToItsStatusIdOnTheAccountsInstance(): void
    {
        $account = SocialAccounts::mastodon();
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876']])]);

        $post = $this->resolver($http)->resolve($account, self::STATUS_URL);

        self::assertSame($account, $post->account);
        self::assertSame('109876', $post->postId);
        self::assertNull($post->contentId);
        self::assertNull($post->existingLikeId);
        self::assertNull($post->existingRepostId);
        self::assertTrue($post->canRepost);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
        self::assertNull($post->counts);
    }

    public function testAPostCarriesTheCountsTheAccountsInstanceAnswered(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([[
            'id' => '109876',
            'favourites_count' => 5,
            'reblogs_count' => 2,
            'replies_count' => 1,
        ]])]);

        $post = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertEquals(new SocialCountsModel(5, 2, 1, null), $post->counts);
    }

    public function testAStatusTheAccountFavouritedIsAlreadyLikedAsItsStatusId(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876', 'favourited' => true]])]);

        $post = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertSame('109876', $post->existingLikeId);
    }

    public function testAStatusTheAccountBoostedIsAlreadyRepostedAsItsStatusId(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876', 'reblogged' => true]])]);

        $post = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertSame('109876', $post->existingRepostId);
    }

    /** @param array<string, string> $author */
    #[DataProvider('authors')]
    public function testAStatusCarriesItsAuthorVisibilityAndQuoteApproval(array $author, string $identity): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([[
            'id' => '109876',
            'visibility' => 'unlisted',
            'account' => $author,
            'quote_approval' => ['current_user' => 'automatic'],
        ]])]);

        $context = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL)->responseContext;

        self::assertSame($identity, $context->authorIdentity);
        self::assertSame('unlisted', $context->visibility);
        self::assertTrue($context->canReply);
        self::assertTrue($context->canQuote);
        self::assertNull($context->threadRoot);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function authors(): iterable
    {
        yield 'local' => [['acct' => 'Alice', 'url' => 'https://mastodon.example/@Alice'], 'alice@mastodon.example'];
        yield 'remote' => [['acct' => 'ann@hachyderm.io', 'url' => 'https://hachyderm.io/@ann'], 'ann@hachyderm.io'];
    }

    public function testAStatusIsPreviewedByItsAuthorAndPlainText(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([[
            'id' => '109876',
            'account' => ['display_name' => 'Ann', 'acct' => 'ann@hachyderm.io', 'url' => 'https://hachyderm.io/@ann'],
            'content' => '<p>Hello <b>world</b></p><p>&amp; more</p>',
        ]])]);

        $context = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL)->responseContext;

        self::assertEquals(new PostPreviewModel('Ann', '@ann@hachyderm.io', 'Hello world & more'), $context->preview);
    }

    public function testAStatusIsPreviewedWithItsLinkCard(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([[
            'id' => '109876',
            'content' => '<p>Senate passes the bill</p>',
            'card' => [
                'url' => 'https://www.politico.com/news/bill',
                'title' => 'Senate passes the bill',
                'description' => 'After a long night',
                'image' => 'https://files.mastodon.example/cards/bill.jpg',
                'provider_name' => 'POLITICO',
            ],
        ]])]);

        $context = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL)->responseContext;

        self::assertEquals(
            new LinkCardContent(
                'https://www.politico.com/news/bill',
                'Senate passes the bill',
                'After a long night',
                'https://files.mastodon.example/cards/bill.jpg',
                'POLITICO',
            ),
            $context->preview->card,
        );
    }

    public function testAStatusWithoutAuthorOrContentIsPreviewedEmpty(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876']])]);

        $context = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL)->responseContext;

        self::assertEquals(new PostPreviewModel(), $context->preview);
    }

    public function testAStatusWithoutAccountVisibilityOrQuoteApprovalNamesNeitherAndTakesNoQuote(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876']])]);

        $context = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL)->responseContext;

        self::assertNull($context->authorIdentity);
        self::assertNull($context->visibility);
        self::assertFalse($context->canQuote);
    }

    #[DataProvider('visibilities')]
    public function testOnlyAPublicOrUnlistedStatusCanBeBoosted(string $visibility, bool $canRepost): void
    {
        $http = new RoutedHttpClient([
            self::SEARCH => self::statuses([['id' => '109876', 'visibility' => $visibility]]),
        ]);

        $post = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertSame($canRepost, $post->canRepost);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function visibilities(): iterable
    {
        yield 'public' => ['public', true];
        yield 'unlisted' => ['unlisted', true];
        yield 'private' => ['private', false];
        yield 'direct' => ['direct', false];
    }

    public function testAnAddressCannotInjectSearchParameters(): void
    {
        $address = 'https://mastodon.social/@a/1&resolve=false#x';
        $expected = 'GET https://mastodon.example/api/v2/search?q='
            . 'https%3A%2F%2Fmastodon.social%2F%40a%2F1%26resolve%3Dfalse%23x&resolve=true&type=statuses&limit=1';
        $http = new RoutedHttpClient([$expected => self::statuses([['id' => '5']])]);

        self::assertSame('5', $this->resolver($http)->resolve(SocialAccounts::mastodon(), $address)->postId);
    }

    public function testAPostTheInstanceCannotFindIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([])]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    #[DataProvider('foreignStatusIds')]
    public function testAStatusIdThatCouldLeaveItsPathIsAnUnreachableNetwork(string $statusId): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => $statusId]])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignStatusIds(): iterable
    {
        yield 'parent path' => ['../1'];
        yield 'slash' => ['1/favourite'];
        yield 'query' => ['1?x=2'];
        yield 'too long' => [str_repeat('1', 65)];
    }

    public function testAFlakeIdOfAnotherServerIsKept(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => 'AbC123xyz']])]);

        $post = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertSame('AbC123xyz', $post->postId);
    }

    public function testARefusedTokenIsARefusedCredential(): void
    {
        $refusal = new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
        $http = new RoutedHttpClient([self::SEARCH => $refusal]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    public function testATokenWithoutTheScopeIsAMissingPermissionNotARefusedCredential(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::forbidden(self::OUTSIDE_SCOPES)]);

        $this->expectException(SocialTokenScopeMissingException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    public function testAnyOtherForbiddenSearchIsARefusalOfThePostNotOfTheToken(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::forbidden('Your login is currently disabled')]);

        $this->expectException(SocialPostRefusedException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    private function resolver(RoutedHttpClient $http): MastodonPostResolver
    {
        return new MastodonPostResolver(SocialApiWiring::mastodonAccountApi($http->client()));
    }

    private static function forbidden(string $error): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => $error], \JSON_THROW_ON_ERROR),
            ['http_code' => Response::HTTP_FORBIDDEN],
        );
    }

    /** @param list<array<string, string|int|bool|array<string, string>>> $statuses */
    private static function statuses(array $statuses): MockResponse
    {
        return new MockResponse(json_encode(
            ['accounts' => [], 'statuses' => $statuses, 'hashtags' => []],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
