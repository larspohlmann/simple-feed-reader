<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use App\Service\Content\Comments\CommentsCache;
use App\Service\Content\Comments\CommentsLoader;
use App\Service\Content\Comments\CommentsSource\LemmyCommentsSource;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Outbound\Fetch\DnsResolver\NativeDnsResolver;
use App\Service\Social\Social\ActingAccountChooser;
use App\Service\Social\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Social\DiscussionVotes;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use App\Service\Social\Social\PostVoter\PostVoterInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscussionVotesTest extends DbTestCase
{
    use ReadsFixtures;
    use SeedsUsers;

    private const string FIXTURES = 'lemmy/';
    private const string LIKE = 'POST https://lemmy.example/api/v3/post/like';
    private const string HOME_POST = 'GET https://lemmy.example/api/v3/post?id=42';
    private const string COMMENT_ADDRESS = 'https://origin.example/comment/5';
    private const string RESOLVE_COMMENT = 'GET https://lemmy.example/api/v3/resolve_object?q='
        . 'https%3A%2F%2Forigin.example%2Fcomment%2F5';
    private const string COMMENT_LIKE = 'POST https://lemmy.example/api/v3/comment/like';

    private RoutedHttpClient $http;
    private User $owner;
    private ?CanonicalItem $item = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('discussion-votes@example.test');
    }

    public function testAVoteIsCastAndMirrored(): void
    {
        $votes = $this->votes([self::LIKE => $this->buildResponse('post-like.json')]);
        $account = $this->lemmyAccount();
        $entry = $this->lemmyPost();

        $result = $votes->vote($this->owner, $entry, VoteScore::Up);

        self::assertEquals(new DiscussionVoteResultModel(VoteScore::Up, 43), $result);
        self::assertSame([self::LIKE], $this->http->requestLines());
        self::assertSame('{"post_id":42,"score":1}', $this->http->requests[0]['body']);
        self::assertSame([[VoteScore::Up, $account->requireId()]], $this->storedVotes());
    }

    public function testAVoteOfNoneDropsTheStoredRow(): void
    {
        $votes = $this->votes([self::LIKE => $this->buildResponse('post-like-no-vote.json')]);
        $account = $this->lemmyAccount();
        $entry = $this->lemmyPost();
        $this->storeVote($account, VoteScore::Up);

        $result = $votes->vote($this->owner, $entry, VoteScore::None);

        self::assertEquals(new DiscussionVoteResultModel(VoteScore::None, 43), $result);
        self::assertSame('{"post_id":42,"score":0}', $this->http->requests[0]['body']);
        self::assertSame([], $this->storedVotes());
    }

    public function testEveryCopyActsThroughTheAccountOfTheStoredVote(): void
    {
        $this->lemmyAccount();
        $caster = $this->lemmyAccount('feddit.example');
        $this->lemmyPost();
        $this->storeVote($caster, VoteScore::Up);
        $copy = $this->persistCopy($this->feed('https://example.com/lemmy.xml'));
        $voter = $this->createMock(PostVoterInterface::class);
        $voter->method('isVotablePost')->willReturn(true);
        $voter->expects($this->once())->method('castVoteOnPost')
            ->with(self::identicalTo($caster), self::identicalTo($copy), VoteScore::Down)
            ->willReturn(new DiscussionVoteResultModel(VoteScore::Down, 3));
        $voter->expects($this->once())->method('fetchPostVote')
            ->with(self::identicalTo($caster), self::identicalTo($copy))
            ->willReturn(VoteScore::Down);
        $votes = $this->votesThrough([SocialNetwork::Lemmy->value => $voter]);

        $votes->vote($this->owner, $copy, VoteScore::Down);
        $votes->syncFromNetwork($this->owner, $copy);

        self::assertSame([[VoteScore::Down, $caster->requireId()]], $this->storedVotes());
    }

    public function testWithoutAStoredVoteTheCopysOwnAccountVotes(): void
    {
        $this->lemmyAccount();
        $homeOwner = $this->lemmyAccount('feddit.example');
        $homeFeed = $this->feed(sprintf('social:lemmy:%d:home', $homeOwner->requireId()));
        $homeFeed->setSourceFormat(SourceFormat::LEMMY_API);
        $this->entityManager->persist(new SocialFeed($homeOwner, $homeFeed, SocialFeedKind::Home, ''));
        $copy = $this->persistCopy($homeFeed);
        $voter = $this->createMock(PostVoterInterface::class);
        $voter->method('isVotablePost')->willReturn(true);
        $voter->expects($this->once())->method('castVoteOnPost')
            ->with(self::identicalTo($homeOwner), self::identicalTo($copy), VoteScore::Up)
            ->willReturn(new DiscussionVoteResultModel(VoteScore::Up, 3));

        $this->votesThrough([SocialNetwork::Lemmy->value => $voter])->vote($this->owner, $copy, VoteScore::Up);

        self::assertSame([[VoteScore::Up, $homeOwner->requireId()]], $this->storedVotes());
    }

    public function testAnEntryWithoutAnItemIsRefused(): void
    {
        $votes = $this->votes([]);
        $this->lemmyAccount();
        $entry = LemmyPostEntries::buildEntry($this->feed('https://lemmy.example/feeds/c/technology.xml'));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->expectExceptionObject(new NotASocialPostException('This entry is not a Lemmy post.'));

        $votes->vote($this->owner, $entry, VoteScore::Up);
    }

    public function testACommentVoteForgetsTheCachedComments(): void
    {
        $votes = $this->votes([
            self::RESOLVE_COMMENT => $this->buildResponse('resolve-comment.json'),
            self::COMMENT_LIKE => $this->buildResponse('comment-like.json'),
        ]);
        $account = $this->lemmyAccount();
        $entry = $this->lemmyPost();
        $cache = self::getContainer()->get(CommentsCache::class);
        self::assertInstanceOf(CommentsCache::class, $cache);
        $key = LemmyCommentsSource::class . ' ' . LemmyPostEntries::PAGE . ' account:' . $account->requireId();
        $cache->remember($key, CommentsAnswerModel::settled('{}'), new \DateTimeImmutable());

        $result = $votes->voteOnComment($this->owner, $entry, self::COMMENT_ADDRESS, VoteScore::Up);

        self::assertEquals(new DiscussionVoteResultModel(VoteScore::Up, 5), $result);
        self::assertSame('{"comment_id":77,"score":1}', $this->http->requests[1]['body']);
        self::assertNull($cache->find($key));
        self::assertSame([], $this->storedVotes());
    }

    public function testASyncMirrorsWhatTheNetworkReports(): void
    {
        $votes = $this->votes([self::HOME_POST => $this->buildResponse('post-like-downvoted.json')]);
        $account = $this->lemmyAccount();
        $entry = $this->lemmyPost();

        self::assertSame(VoteScore::Down, $votes->syncFromNetwork($this->owner, $entry));

        self::assertSame([self::HOME_POST], $this->http->requestLines());
        self::assertSame([[VoteScore::Down, $account->requireId()]], $this->storedVotes());
    }

    public function testAnEntryNoVoterTakesIsRefusedWithTheLemmyText(): void
    {
        $votes = $this->votes([]);
        $this->lemmyAccount();

        $this->expectExceptionObject(new NotASocialPostException('This entry is not a Lemmy post.'));

        $votes->vote($this->owner, $this->rssEntry(), VoteScore::Up);
    }

    public function testACommentAddressThatIsNotHttpsIsRefusedWithTheLemmyText(): void
    {
        $votes = $this->votes([]);
        $this->lemmyAccount();

        $this->expectExceptionObject(new NotASocialPostException('The address names no Lemmy comment.'));

        $votes->voteOnComment($this->owner, $this->lemmyPost(), 'http://origin.example/comment/5', VoteScore::Up);
    }

    public function testWithSeveralVotersTheRefusalNamesEveryVotingNetwork(): void
    {
        $votes = $this->votesOnEveryNetworkThrough(self::createStub(PostVoterInterface::class));

        $this->expectExceptionObject(
            new NotASocialPostException('This entry is not a Bluesky, Mastodon or Lemmy post.'),
        );

        $votes->vote($this->owner, $this->rssEntry(), VoteScore::Up);
    }

    public function testWithSeveralVotersTheCommentRefusalNamesEveryVotingNetwork(): void
    {
        $voter = self::createStub(PostVoterInterface::class);
        $voter->method('isVotablePost')->willReturn(true);
        $votes = $this->votesOnEveryNetworkThrough($voter);

        $this->expectExceptionObject(
            new NotASocialPostException('The address names no Bluesky, Mastodon or Lemmy comment.'),
        );

        $votes->voteOnComment($this->owner, $this->rssEntry(), 'http://origin.example/comment/5', VoteScore::Up);
    }

    private function votesOnEveryNetworkThrough(PostVoterInterface $voter): DiscussionVotes
    {
        $voters = [];
        foreach (SocialNetwork::cases() as $network) {
            $voters[$network->value] = $voter;
        }

        return $this->votesThrough($voters);
    }

    /** @param array<string, PostVoterInterface> $votersByNetwork */
    private function votesThrough(array $votersByNetwork): DiscussionVotes
    {
        $container = self::getContainer();

        return new DiscussionVotes(
            self::service($container, ActingAccountChooser::class),
            new ServiceLocator(array_map(
                static fn (PostVoterInterface $voter): \Closure => static fn (): PostVoterInterface => $voter,
                $votersByNetwork,
            )),
            self::service($container, DiscussionVoteRepository::class),
            $this->entityManager,
            self::service($container, NaiveUtcClock::class),
            self::service($container, CommentsLoader::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /** @param array<string, MockResponse> $routes */
    private function votes(array $routes): DiscussionVotes
    {
        $this->http = new RoutedHttpClient($routes);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $this->http->client());
        $container->set(
            NativeDnsResolver::class,
            new StaticDnsResolver(['lemmy.example' => ['93.184.216.34'], 'feddit.example' => ['93.184.216.34']]),
        );
        $votes = $container->get(DiscussionVotes::class);
        self::assertInstanceOf(DiscussionVotes::class, $votes);

        return $votes;
    }

    private function lemmyAccount(string $host = 'lemmy.example'): SocialAccount
    {
        $instanceCipher = self::getContainer()->get(InstanceSecretCipher::class);
        self::assertInstanceOf(InstanceSecretCipher::class, $instanceCipher);
        $account = new SocialAccount(
            $this->owner,
            SocialNetwork::Lemmy,
            'lars@' . $host,
            '@lars@' . $host,
            'https://' . $host,
            new SocialCredentialCipher($instanceCipher)->seal($this->owner->requireId(), SocialAccounts::LEMMY_JWT),
            '',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function lemmyPost(): Entry
    {
        return $this->persistCopy($this->feed('https://lemmy.example/feeds/c/technology.xml'));
    }

    /** A copy of the post, linked to the item every copy in the test shares. */
    private function persistCopy(Feed $feed): Entry
    {
        $entry = LemmyPostEntries::buildEntry($feed);
        $this->entityManager->persist($entry);
        if (null === $this->item) {
            $this->item = LemmyPostEntries::linkItem($entry);
            $this->entityManager->persist($this->item);
        } else {
            $entry->linkTo($this->item);
        }
        $this->entityManager->flush();

        return $entry;
    }

    private function rssEntry(): Entry
    {
        $published = new \DateTimeImmutable('2026-10-12 10:00:00');
        $entry = new Entry(
            $this->feed('https://example.com/feed.xml'),
            'https://example.com/plain',
            'https://example.com/plain',
            'Plain',
            $published,
            $published,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function storeVote(SocialAccount $account, VoteScore $score): void
    {
        $castAt = new \DateTimeImmutable('2026-10-12 10:00:00');
        $item = $this->item ?? throw new \LogicException('A vote needs the post first.');
        $this->entityManager->persist(new DiscussionVote($this->owner, $item, $account, $score, $castAt));
        $this->entityManager->flush();
    }

    /** @return list<array{VoteScore, int}> */
    private function storedVotes(): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn (DiscussionVote $vote): array => [$vote->getScore(), $vote->getSocialAccount()->requireId()],
            $this->entityManager->getRepository(DiscussionVote::class)->findAll(),
        );
    }

    private function buildResponse(string $name): MockResponse
    {
        return new MockResponse($this->fixture(self::FIXTURES . $name));
    }
}
