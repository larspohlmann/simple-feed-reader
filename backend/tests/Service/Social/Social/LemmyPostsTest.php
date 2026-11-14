<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\VoteScore;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\LemmyPosts;
use App\Service\Social\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyPostsTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/lemmy/';
    private const string HOME_POST = 'GET https://lemmy.example/api/v3/post?id=42';
    private const string FEDERATED_POST = 'GET https://feddit.example/api/v3/post?id=7';
    private const string RESOLVE = 'GET https://lemmy.example/api/v3/resolve_object?q=';
    private const string ORIGIN_POST = 'https%3A%2F%2Forigin.example%2Fpost%2F3';
    private const string ORIGIN_COMMENT = 'https%3A%2F%2Forigin.example%2Fcomment%2F5';

    public function testAPostOnTheHomeInstanceIsReadByItsOwnId(): void
    {
        $http = new RoutedHttpClient([self::HOME_POST => self::fixture('post-as-account.json')]);

        $target = $this->posts($http)->postOn($this->account(), new LemmyPostModel('https://lemmy.example', 42));

        self::assertSame([self::HOME_POST], $http->requestLines());
        self::assertContains('Authorization: Bearer ' . SocialAccounts::LEMMY_JWT, $http->requests[0]['headers']);
        self::assertSame(42, $target->postId);
        self::assertSame(42, $target->score);
        self::assertSame(VoteScore::None, $target->myVote);
    }

    public function testAHomeInstanceHostDifferingOnlyInCaseIsStillTheHomeInstance(): void
    {
        $http = new RoutedHttpClient([self::HOME_POST => self::fixture('post-as-account.json')]);

        $this->posts($http)->postOn($this->account(), new LemmyPostModel('https://Lemmy.Example', 42));

        self::assertSame([self::HOME_POST], $http->requestLines());
    }

    public function testAFederatedCopyResolvesItsOriginOnTheHomeInstance(): void
    {
        $http = $this->federated(self::fixture('resolve-post.json'));

        $target = $this->posts($http)->postOn($this->account(), new LemmyPostModel('https://feddit.example', 7));

        self::assertSame([self::FEDERATED_POST, self::RESOLVE . self::ORIGIN_POST], $http->requestLines());
        self::assertSame(990, $target->postId);
    }

    public function testAPostOnTheHomeInstanceNeedsNoRequestForItsHomeId(): void
    {
        $http = new RoutedHttpClient([]);

        $postId = $this->posts($http)->homePostIdOf($this->account(), new LemmyPostModel('https://lemmy.example', 42));

        self::assertSame(42, $postId);
        self::assertSame([], $http->requests);
    }

    public function testAFederatedCopyFindsItsHomeIdByResolving(): void
    {
        $http = $this->federated(self::fixture('resolve-post.json'));

        $postId = $this->posts($http)->homePostIdOf($this->account(), new LemmyPostModel('https://feddit.example', 7));

        self::assertSame(990, $postId);
        self::assertSame([self::FEDERATED_POST, self::RESOLVE . self::ORIGIN_POST], $http->requestLines());
    }

    public function testAFederatedCopyIsLookedUpWithoutTheToken(): void
    {
        $http = $this->federated(self::fixture('resolve-post.json'));

        $this->posts($http)->postOn($this->account(), new LemmyPostModel('https://feddit.example', 7));

        self::assertSame([], self::authorizationOf($http->requests[0]['headers']));
        self::assertSame(
            ['Authorization: Bearer ' . SocialAccounts::LEMMY_JWT],
            self::authorizationOf($http->requests[1]['headers']),
        );
    }

    public function testAnOriginThatIsNotHttpsIsNotFound(): void
    {
        $insecure = str_replace(
            'https://origin.example',
            'http://origin.example',
            (string) file_get_contents(self::FIXTURES . 'post-anonymous.json'),
        );
        $http = new RoutedHttpClient([self::FEDERATED_POST => new MockResponse($insecure)]);

        try {
            $this->posts($http)->postOn($this->account(), new LemmyPostModel('https://feddit.example', 7));
            self::fail('Expected the post to be not found.');
        } catch (SocialPostNotFoundException) {
            self::assertSame([self::FEDERATED_POST], $http->requestLines());
        }
    }

    public function testAResolveWithoutAPostIsNotFound(): void
    {
        $http = $this->federated(self::fixture('resolve-comment.json'));

        $this->expectException(SocialPostNotFoundException::class);

        $this->posts($http)->postOn($this->account(), new LemmyPostModel('https://feddit.example', 7));
    }

    public function testACommentResolvesByItsAddress(): void
    {
        $http = new RoutedHttpClient([self::RESOLVE . self::ORIGIN_COMMENT => self::fixture('resolve-comment.json')]);

        $target = $this->posts($http)->commentOn($this->account(), 'https://origin.example/comment/5');

        self::assertSame(77, $target->commentId);
        self::assertSame(990, $target->postId);
        self::assertSame(VoteScore::Down, $target->myVote);
        self::assertSame(3, $target->score);
    }

    public function testACommentAddressThatIsNotHttpsIsNotFound(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->posts($http)->commentOn($this->account(), 'http://origin.example/comment/5');
            self::fail('Expected the comment to be not found.');
        } catch (SocialPostNotFoundException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testAResolveWithoutACommentIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::RESOLVE . self::ORIGIN_COMMENT => self::fixture('resolve-post.json')]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->posts($http)->commentOn($this->account(), 'https://origin.example/comment/5');
    }

    private function federated(MockResponse $resolved): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::FEDERATED_POST => self::fixture('post-anonymous.json'),
            self::RESOLVE . self::ORIGIN_POST => $resolved,
        ]);
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private static function authorizationOf(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            static fn (string $header): bool => str_starts_with(strtolower($header), 'authorization:'),
        ));
    }

    private function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    private function posts(RoutedHttpClient $http): LemmyPosts
    {
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'lemmy.example' => ['93.184.216.34'],
                'feddit.example' => ['93.184.216.35'],
            ]),
            new IpValidator(),
        );
        $client = new LemmyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new LemmyPosts(
            new LemmyAccountApi($client, new StoredCredential(SocialAccounts::cipher())),
            $client,
        );
    }

    private static function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::FIXTURES . $name));
    }
}
