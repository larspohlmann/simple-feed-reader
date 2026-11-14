<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Enum\SocialFeedKind;
use App\Entity\Discussion;
use App\Entity\Headline;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Social\Completion\Model\KnownSharesModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\PrivateFeeds;
use App\Service\Social\Social\SocialCandidates\SocialCandidatesInterface;
use App\Service\Social\Social\SocialSearch\SocialSearchInterface;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\StubSocialSearch;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SocialSearchControllerTest extends ApiTestCase
{
    /** Must match framework.rate_limiter.social_search_preview.limit in rate_limiter.yaml. */
    private const int SEARCH_PREVIEW_BUDGET = 30;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testTheSearchPreviewRefusesAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me/social-accounts/1/search-preview?query=rss');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheSearchPreviewOfAnotherUsersAccountAnswers404(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageOf(0)));
        $owner = $this->signIn($client, 'search-owner@example.test');
        $account = $this->blueskyAccountOf($owner);
        $this->signIn($client, 'search-intruder@example.test');

        $this->requestSearchPreview($client, $account, 'rss');

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_account_not_found', $this->payload($client)['type']);
    }

    public function testALemmyAccountPreviewsASearch(): void
    {
        $search = new StubSocialSearch(self::pageOf(2));
        $client = $this->searchClientWith($search);
        $owner = $this->signIn($client, 'search-lemmy@example.test');
        $account = SocialFeeds::persistedAccount($this->entityManager(), SocialAccounts::lemmyOf($owner));

        $this->requestSearchPreview($client, $account, 'rss');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['count' => 2, 'posts' => array_map(self::previewPost(...), [1, 2])],
            $this->payload($client),
        );
        self::assertSame('rss', $search->query);
    }

    public function testALemmyPreviewPostLinksItsThreadAndLeadsWithItsTitle(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageWith([
            self::lemmyPostWith('<p>The <b>whole</b> text</p>'),
            self::lemmyPostWith(null),
        ])));
        $owner = $this->signIn($client, 'search-lemmy-title@example.test');
        $account = SocialFeeds::persistedAccount($this->entityManager(), SocialAccounts::lemmyOf($owner));

        $this->requestSearchPreview($client, $account, 'rss');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                self::lemmyPreviewPost('A reader for feeds — The whole text'),
                self::lemmyPreviewPost('A reader for feeds'),
            ],
            $this->payload($client)['posts'],
        );
    }

    public function testAPreviewPostWithItsOwnLinkKeepsItOverItsThread(): void
    {
        $post = new ParsedEntryModel(
            'guid-1',
            'https://bsky.app/profile/amy/post/1',
            Headline::derived('Post 1'),
            '@amy.bsky.social',
            null,
            self::postHtml(1),
            new \DateTimeImmutable(self::publishedAt(1)),
            discussion: Discussion::platformThread('https://lemmy.example/post/7'),
            authorAvatarUrl: 'https://cdn.test/amy.jpg',
        );
        $client = $this->searchClientWith(new StubSocialSearch(self::pageWith([$post])));
        $user = $this->signIn($client, 'search-own-link@example.test');

        $this->requestSearchPreview($client, $this->blueskyAccountOf($user), 'rss');

        self::assertSame([self::previewPost(1)], $this->payload($client)['posts']);
    }

    public function testABlankSearchPreviewQueryIsRefused(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageOf(0)));
        $user = $this->signIn($client, 'search-blank@example.test');

        $this->requestSearchPreview($client, $this->blueskyAccountOf($user), '  ');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testATooLongSearchPreviewQueryIsRefused(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageOf(0)));
        $user = $this->signIn($client, 'search-long@example.test');

        $this->requestSearchPreview($client, $this->blueskyAccountOf($user), str_repeat('a', 513));

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheSearchPreviewCountsEveryMatchAndShowsTheFirstTenOfTheNormalisedQuery(): void
    {
        $search = new StubSocialSearch(self::pageOf(12));
        $client = $this->searchClientWith($search);
        $user = $this->signIn($client, 'search-preview@example.test');

        $this->requestSearchPreview($client, $this->blueskyAccountOf($user), ' rss  reader');

        self::assertResponseIsSuccessful();
        self::assertSame('rss reader', $search->query);
        $payload = $this->payload($client);
        self::assertSame(12, $payload['count']);
        self::assertSame(
            array_map(self::previewPost(...), range(1, 10)),
            $payload['posts'],
        );
    }

    public function testAPreviewPostWithoutTextShowsItsHeadline(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageWith([self::postWith(1, null)])));
        $user = $this->signIn($client, 'search-headline@example.test');

        $this->requestSearchPreview($client, $this->blueskyAccountOf($user), 'rss');

        self::assertResponseIsSuccessful();
        self::assertSame([self::previewPost(1, 'Post 1')], $this->payload($client)['posts']);
    }

    public function testASearchPreviewWithoutMatchesIsEmpty(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageOf(0)));
        $user = $this->signIn($client, 'search-empty@example.test');

        $this->requestSearchPreview($client, $this->blueskyAccountOf($user), 'rss');

        self::assertResponseIsSuccessful();
        self::assertSame(['count' => 0, 'posts' => []], $this->payload($client));
    }

    public function testTheSearchPreviewHasABudgetOfItsOwn(): void
    {
        $client = $this->searchClientWith(new StubSocialSearch(self::pageOf(0)));
        self::getContainer()->set(SocialCandidatesInterface::class, $this->nothingFollowed());
        $user = $this->signIn($client, 'search-budget@example.test');
        $account = $this->blueskyAccountOf($user);
        for ($attempt = 0; $attempt < self::SEARCH_PREVIEW_BUDGET; ++$attempt) {
            $this->requestSearchPreview($client, $account, 'rss');
            self::assertResponseIsSuccessful();
        }

        $this->requestSearchPreview($client, $account, 'rss');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->requestCandidates($client, $account);
        self::assertResponseIsSuccessful();
    }

    public function testANewQueryRefusesAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request(
            'PUT',
            '/api/subscriptions/1/search-query',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"query": "rss"}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testANewQueryAnswersTheSubscriptionWithIt(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery@example.test');
        $subscription = $this->searchOf($user, 'rss reader');

        $this->putQuery($client, $subscription, '  feed  reader ');

        self::assertResponseIsSuccessful();
        $answer = $this->payload($client)['subscription'];
        self::assertIsArray($answer);
        self::assertSame($subscription->requireId(), $answer['id']);
        self::assertSame('feed reader', $answer['title']);
        self::assertIsArray($answer['social']);
        self::assertSame('feed reader', $answer['social']['query']);
        self::assertSame('search', $answer['social']['kind']);
    }

    public function testTheSameQueryIsANoOpSuccess(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery-same@example.test');
        $subscription = $this->searchOf($user, 'rss reader');

        $this->putQuery($client, $subscription, 'rss reader');

        self::assertResponseIsSuccessful();
    }

    public function testAnotherUsersSearchAnswers404(): void
    {
        $client = static::createClient();
        $owner = $this->factory()->create('requery-owner@example.test');
        $subscription = $this->searchOf($owner, 'rss reader');
        $this->signIn($client, 'requery-intruder@example.test');

        $this->putQuery($client, $subscription, 'feed reader');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testABlankNewQueryIsRefused(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery-blank@example.test');

        $this->putQuery($client, $this->searchOf($user, 'rss reader'), " \t ");

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testANewQueryOverItsLengthIsRefused(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery-long@example.test');

        $this->putQuery($client, $this->searchOf($user, 'rss reader'), str_repeat('a', 513));

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testANewQueryOfExactlyItsLengthIsAccepted(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery-longest@example.test');

        $this->putQuery($client, $this->searchOf($user, 'rss reader'), str_repeat('a', 512));

        self::assertResponseIsSuccessful();
    }

    public function testAFeedThatIsNoSearchIsRefused(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery-home@example.test');
        $home = $this->privateFeeds()->subscribe(
            $this->accountOf($user),
            PrivateFeedModel::timeline(SocialFeedKind::Home),
            [],
        );

        $this->putQuery($client, $home, 'rss');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('not_a_search_feed', $this->payload($client)['type']);
    }

    public function testAQueryTheAccountAlreadySearchesForConflicts(): void
    {
        $client = static::createClient();
        $user = $this->signIn($client, 'requery-twice@example.test');
        $account = $this->accountOf($user);
        $subscription = $this->privateFeeds()->subscribe($account, PrivateFeedModel::search('rss reader'), []);
        $this->privateFeeds()->subscribe($account, PrivateFeedModel::search('feed reader'), []);

        $this->putQuery($client, $subscription, 'feed reader');

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('already_subscribed', $this->payload($client)['type']);
    }

    private function searchClientWith(SocialSearchInterface $search): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        self::getContainer()->set(SocialSearchInterface::class, $search);

        return $client;
    }

    private function requestSearchPreview(KernelBrowser $client, SocialAccount $account, string $query): void
    {
        $client->request('GET', sprintf(
            '/api/me/social-accounts/%d/search-preview?query=%s',
            $account->requireId(),
            rawurlencode($query),
        ));
    }

    private static function pageOf(int $entries): TimelinePageModel
    {
        return self::pageWith(array_map(
            static fn (int $number): ParsedEntryModel => self::postWith($number, self::postHtml($number)),
            0 === $entries ? [] : range(1, $entries),
        ));
    }

    /** @param list<ParsedEntryModel> $posts */
    private static function pageWith(array $posts): TimelinePageModel
    {
        return TimelinePageModel::of($posts, KnownSharesModel::none(), null);
    }

    private static function postWith(int $number, ?string $contentHtml): ParsedEntryModel
    {
        return new ParsedEntryModel(
            'guid-' . $number,
            'https://bsky.app/profile/amy/post/' . $number,
            Headline::derived('Post ' . $number),
            '@amy.bsky.social',
            null,
            $contentHtml,
            new \DateTimeImmutable(self::publishedAt($number)),
            authorAvatarUrl: 'https://cdn.test/amy.jpg',
        );
    }

    private static function lemmyPostWith(?string $contentHtml): ParsedEntryModel
    {
        $post = new ParsedEntryModel(
            'https://lemmy.example/post/7',
            null,
            Headline::fromFeed('A reader for feeds'),
            'amy',
            null,
            $contentHtml,
            new \DateTimeImmutable(self::publishedAt(7)),
        );

        return $post->asDiscussionThread(Discussion::platformThread('https://lemmy.example/post/7'));
    }

    /** @return array<string, ?string> */
    private static function lemmyPreviewPost(string $text): array
    {
        return [
            'url' => 'https://lemmy.example/post/7',
            'author' => 'amy',
            'authorAvatarUrl' => null,
            'text' => $text,
            'publishedAt' => self::publishedAt(7),
        ];
    }

    private static function postHtml(int $number): string
    {
        return sprintf('<p>Post %d at St. Pauli, the <b>whole</b> text</p>', $number);
    }

    /** @return array<string, string> */
    private static function previewPost(int $number, ?string $text = null): array
    {
        return [
            'url' => 'https://bsky.app/profile/amy/post/' . $number,
            'author' => '@amy.bsky.social',
            'authorAvatarUrl' => 'https://cdn.test/amy.jpg',
            'text' => $text ?? sprintf('Post %d at St. Pauli, the whole text', $number),
            'publishedAt' => self::publishedAt($number),
        ];
    }

    private function putQuery(KernelBrowser $client, Subscription $subscription, string $query): void
    {
        $client->request(
            'PUT',
            sprintf('/api/subscriptions/%d/search-query', $subscription->requireId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['query' => $query], \JSON_THROW_ON_ERROR),
        );
    }

    private function searchOf(User $user, string $query): Subscription
    {
        return $this->privateFeeds()->subscribe($this->accountOf($user), PrivateFeedModel::search($query), []);
    }

    private function accountOf(User $user): SocialAccount
    {
        return $this->blueskyAccountOf($user);
    }

    private function privateFeeds(): PrivateFeeds
    {
        $privateFeeds = self::getContainer()->get(PrivateFeeds::class);
        self::assertInstanceOf(PrivateFeeds::class, $privateFeeds);

        return $privateFeeds;
    }

    private static function publishedAt(int $number): string
    {
        return sprintf('2026-11-07T10:%02d:00+00:00', $number);
    }

    private function nothingFollowed(): StubSocialCandidates
    {
        return new StubSocialCandidates(new NetworkCandidatesModel([], [], [], false));
    }

    private function blueskyAccountOf(User $user): SocialAccount
    {
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function requestCandidates(KernelBrowser $client, SocialAccount $account): void
    {
        $client->request('GET', sprintf('/api/me/social-accounts/%d/import-candidates', $account->requireId()));
    }
}
