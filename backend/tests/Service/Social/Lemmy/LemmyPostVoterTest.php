<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Enum\VoteScore;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyApiClient;
use App\Service\Social\Lemmy\LemmyPosts;
use App\Service\Social\Lemmy\LemmyPostVoter;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use App\Service\Social\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyPostVoterTest extends TestCase
{
    use ReadsFixtures;

    private const string FIXTURES = 'lemmy/';
    private const string LIKE = 'POST https://lemmy.example/api/v3/post/like';
    private const string HOME_POST = 'GET https://lemmy.example/api/v3/post?id=42';
    private const string RESOLVE_COMMENT = 'GET https://lemmy.example/api/v3/resolve_object?q='
        . 'https%3A%2F%2Forigin.example%2Fcomment%2F5';
    private const string COMMENT_LIKE = 'POST https://lemmy.example/api/v3/comment/like';

    public function testAVoteOnAPostReachesTheHomeInstance(): void
    {
        $http = new RoutedHttpClient([self::LIKE => $this->buildResponse('post-like.json')]);

        $result = $this->voter($http)->castVoteOnPost($this->account(), $this->lemmyPost(), VoteScore::Up);

        self::assertEquals(new DiscussionVoteResultModel(VoteScore::Up, 43), $result);
        self::assertSame([self::LIKE], $http->requestLines());
        self::assertSame('{"post_id":42,"score":1}', $http->requests[0]['body']);
    }

    public function testAVoteOnACommentResolvesTheCommentFirst(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_COMMENT => $this->buildResponse('resolve-comment.json'),
            self::COMMENT_LIKE => $this->buildResponse('comment-like.json'),
        ]);

        $result = $this->voter($http)->castVoteOnComment(
            $this->account(),
            'https://origin.example/comment/5',
            VoteScore::Up,
        );

        self::assertEquals(new DiscussionVoteResultModel(VoteScore::Up, 5), $result);
        self::assertSame([self::RESOLVE_COMMENT, self::COMMENT_LIKE], $http->requestLines());
        self::assertSame('{"comment_id":77,"score":1}', $http->requests[1]['body']);
    }

    public function testThePostVoteIsWhatTheHomeInstanceReports(): void
    {
        $http = new RoutedHttpClient([self::HOME_POST => $this->buildResponse('post-like-downvoted.json')]);

        self::assertSame(
            VoteScore::Down,
            $this->voter($http)->fetchPostVote($this->account(), $this->lemmyPost()),
        );
        self::assertSame([self::HOME_POST], $http->requestLines());
    }

    public function testALemmyPostIsVotable(): void
    {
        self::assertTrue($this->voter(new RoutedHttpClient([]))->isVotablePost($this->lemmyPost()));
    }

    public function testAnRssEntryIsNotVotable(): void
    {
        self::assertFalse($this->voter(new RoutedHttpClient([]))->isVotablePost(self::rssEntry()));
    }

    public function testAVoteOnAnRssEntryIsRefusedBeforeAnyCall(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->voter($http)->castVoteOnPost($this->account(), self::rssEntry(), VoteScore::Up);
            self::fail('An RSS entry must not be voted on.');
        } catch (NotASocialPostException $exception) {
            self::assertSame('This entry is not a Lemmy post.', $exception->getMessage());
            self::assertSame([], $http->requests);
        }
    }

    private static function rssEntry(): Entry
    {
        $published = new \DateTimeImmutable('2026-10-12 10:00:00');

        return new Entry(
            new Feed('https://example.com/feed.xml'),
            'https://example.com/plain',
            'https://example.com/plain',
            'Plain',
            $published,
            $published,
        );
    }

    private function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    private function lemmyPost(): Entry
    {
        return LemmyPostEntries::on(new Feed('https://lemmy.example/feeds/c/technology.xml'));
    }

    private function voter(RoutedHttpClient $http): LemmyPostVoter
    {
        $guard = new UrlGuard(new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]), new IpValidator());
        $client = new LemmyApiClient(FetchWiring::patientApiClient($http->client(), $guard));
        $accountApi = new LemmyAccountApi($client, new StoredCredential(SocialAccounts::cipher()));

        return new LemmyPostVoter(
            new LemmyPosts($accountApi, $client),
            $accountApi,
            new ActingAccountGuard(self::createStub(EntityManagerInterface::class)),
        );
    }

    private function buildResponse(string $name): MockResponse
    {
        return new MockResponse($this->fixture(self::FIXTURES . $name));
    }
}
