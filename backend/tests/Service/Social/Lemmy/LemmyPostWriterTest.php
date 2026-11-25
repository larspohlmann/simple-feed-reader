<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\Model\ApiBudgetModel;
use App\Service\Social\Lemmy\LemmyOwnPosts;
use App\Service\Social\Lemmy\LemmyPostWriter;
use App\Service\Social\Publishing\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Publishing\Exception\SocialPostTitleTooShortException;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Publishing\Model\LemmyCommunityPostModel;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LemmyPostWriterTest extends TestCase
{
    use ReadsFixtures;

    private const string COMMENT = 'POST https://lemmy.example/api/v3/comment';
    private const string POST_PATH = 'https://lemmy.example/api/v3/post';
    private const string POST = 'POST ' . self::POST_PATH;
    private const string SENT_AT = '2026-11-25 11:36:09';
    private const string LISTING = 'GET https://lemmy.example/api/v3/user'
        . '?username=lars%40lemmy.example&sort=New&limit=10';
    private const string ARTICLE = 'https://example.com/a';
    private const string PUBLISHED_ADDRESS = 'https://lemmy.example/comment/9001';

    public function testAReplyToAPostIsACommentOnIt(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => $this->created()]);

        $published = $this->writer($http)->publish(
            $this->account(),
            $this->replying('42', null, new PostDraftModel('Hello **there**')),
        );

        self::assertSame('{"content":"Hello **there**","post_id":42}', $http->requests[0]['body']);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::LEMMY_JWT, $http->requests[0]['headers']);
        self::assertEquals(new PublishedPostModel(self::PUBLISHED_ADDRESS, self::PUBLISHED_ADDRESS), $published);
    }

    public function testAReplyToACommentNamesItsParent(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => $this->created()]);

        $this->writer($http)->publish(
            $this->account(),
            $this->replying('990', '77', new PostDraftModel('Agreed')),
        );

        self::assertSame('{"content":"Agreed","post_id":990,"parent_id":77}', $http->requests[0]['body']);
    }

    public function testATextOverTenThousandCharactersIsTooLongAndNeverSent(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->writer($http)->publish(
                $this->account(),
                $this->replying('42', null, new PostDraftModel(str_repeat('ä', 10001))),
            );
            self::fail('A text over the limit is not sent.');
        } catch (SocialPostTooLongException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testTenThousandCharactersFit(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => $this->created()]);

        $this->writer($http)->publish(
            $this->account(),
            $this->replying('42', null, new PostDraftModel(str_repeat('ä', 10000))),
        );

        self::assertCount(1, $http->requests);
    }

    public function testAnAnswerWithoutACommentAddressIsRefused(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => new MockResponse('{"comment_view":{"comment":{"id":1}}}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->publish(
            $this->account(),
            $this->replying('42', null, new PostDraftModel('Hi')),
        );
    }

    public function testRefusesAQuote(): void
    {
        $this->expectExceptionObject(new SocialPostRefusedException('Lemmy takes no quotes.'));

        $this->writer(new RoutedHttpClient([]))->publish(
            $this->account(),
            self::quoting(SocialPostModel::onLemmy($this->account(), '42', null), new PostDraftModel('Hi')),
        );
    }

    public function testALinkIsALinkPostInTheChosenCommunity(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $published = $this->writer($http)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel('Worth a read.', language: 'de'), 'Title', 7),
        );

        self::assertSame(
            '{"name":"Title","url":"https:\/\/example.com\/a","body":"Worth a read.","community_id":7}',
            $http->requests[0]['body'],
        );
        self::assertContains('Authorization: Bearer ' . SocialAccounts::LEMMY_JWT, $http->requests[0]['headers']);
        self::assertEquals(
            new PublishedPostModel('https://lemmy.example/post/501', 'https://lemmy.example/post/501'),
            $published,
        );
    }

    public function testAPostWaitsEightSecondsForTheInstance(): void
    {
        $response = self::postCreated();
        $http = new RoutedHttpClient([self::POST => $response]);

        $this->writer($http)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel('Worth a read.'), 'Title', 7),
        );

        self::assertSame(8.0, $response->getRequestOptions()['timeout']);
    }

    public function testACommentKeepsTheStandardWait(): void
    {
        $response = $this->created();
        $http = new RoutedHttpClient([self::COMMENT => $response]);

        $this->writer($http)->publish(
            $this->account(),
            $this->replying('42', null, new PostDraftModel('Hello')),
        );

        self::assertSame(ApiBudgetModel::STANDARD_TIMEOUT_SECONDS, $response->getRequestOptions()['timeout']);
    }

    public function testATopLevelPostWithoutALinkSendsNoUrl(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->publish(
            $this->account(),
            new PublicationModel(new PostDraftModel('Just text'))
                ->withLemmy(new LemmyCommunityPostModel(7, 'Title')),
        );

        self::assertSame(
            ['name' => 'Title', 'body' => 'Just text', 'community_id' => 7],
            json_decode($http->requests[0]['body'], true),
        );
    }

    public function testABlankTextLeavesOutTheBody(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel(" \n"), 'Title', 7),
        );

        self::assertSame(
            '{"name":"Title","url":"https:\/\/example.com\/a","community_id":7}',
            $http->requests[0]['body'],
        );
    }

    public function testAnAnswerWithoutAPostAddressIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::POST => new MockResponse('{"post_view":{"post":{"id":1}}}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel(''), 'Title', 7),
        );
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('refusedLinkDrafts')]
    public function testAnIncompleteOrOverlongLinkIsRefusedAndNeverSent(
        PublicationModel $publication,
        string $exception,
    ): void {
        $http = new RoutedHttpClient([]);

        try {
            $this->writer($http)->publish($this->account(), $publication);
            self::fail('The link is not sent.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string, array{PublicationModel, class-string<\Throwable>}> */
    public static function refusedLinkDrafts(): iterable
    {
        yield 'without a community' => [
            self::linkPost(new PostDraftModel('Hi'), 'Title', null),
            SocialPostCommunityMissingException::class,
        ];
        yield 'a whitespace title' => [
            self::linkPost(new PostDraftModel('Hi'), "  \n", 7),
            SocialPostTitleTooShortException::class,
        ];
        yield 'no title' => [
            self::linkPost(new PostDraftModel('Hi'), null, 7),
            SocialPostTitleTooShortException::class,
        ];
        yield 'a title of two characters once trimmed' => [
            self::linkPost(new PostDraftModel('Hi'), ' äb ', 7),
            SocialPostTitleTooShortException::class,
        ];
        yield 'a title over two hundred' => [
            self::linkPost(new PostDraftModel('Hi'), str_repeat('ä', 201), 7),
            SocialPostTooLongException::class,
        ];
        yield 'a body over ten thousand' => [
            self::linkPost(new PostDraftModel(str_repeat('ä', 10001)), 'Title', 7),
            SocialPostTooLongException::class,
        ];
        yield 'no Lemmy choices at all' => [
            new PublicationModel(new PostDraftModel('Hi'))->withEmbed(new LinkEmbedModel(self::ARTICLE, 'A')),
            SocialPostCommunityMissingException::class,
        ];
        yield 'the community is checked before the title' => [
            self::linkPost(new PostDraftModel('Hi'), null, null),
            SocialPostCommunityMissingException::class,
        ];
    }

    public function testATooShortTitleSaysHowLongATitleMustBe(): void
    {
        $this->expectExceptionObject(
            new SocialPostTitleTooShortException('A Lemmy title needs at least 3 characters.'),
        );

        $this->writer(new RoutedHttpClient([]))->publish(
            $this->account(),
            self::linkPost(new PostDraftModel(''), 'Hi', 7),
        );
    }

    public function testATooLongTitleSaysHowLongATitleMayBe(): void
    {
        $this->expectExceptionObject(new SocialPostTooLongException('A Lemmy post title holds 200 characters.'));

        $this->writer(new RoutedHttpClient([]))->publish(
            $this->account(),
            self::linkPost(new PostDraftModel(''), str_repeat('ä', 201), 7),
        );
    }

    public function testATitleOfThreeCharactersFits(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel(''), ' Hey ', 7),
        );

        self::assertSame(
            '{"name":"Hey","url":"https:\/\/example.com\/a","community_id":7}',
            $http->requests[0]['body'],
        );
    }

    public function testATitleOfTwoHundredAndABodyOfTenThousandFit(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel(str_repeat('ä', 10000)), str_repeat('ä', 200), 7),
        );

        self::assertCount(1, $http->requests);
    }

    public function testADeletionIsRefused(): void
    {
        $this->expectExceptionObject(
            new SocialPostRefusedException('Deleting a Lemmy comment is not supported here.'),
        );

        $this->writer(new RoutedHttpClient([]))->delete($this->account(), '9001');
    }

    public function testATimedOutPostThatLandedIsPosted(): void
    {
        $http = new RoutedHttpClient([self::POST => new MockResponse(['']), self::LISTING => self::ownListing()]);

        $published = $this->publishTitled($http);

        self::assertEquals(
            new PublishedPostModel('https://lemmy.example/post/501', 'https://lemmy.example/post/501'),
            $published,
        );
    }

    public function testATimedOutPostThatDidNotLandMayHaveGoneThrough(): void
    {
        $clock = new MockClock(self::SENT_AT);
        $http = self::instanceHoldingThePost($clock, 8, static fn (): MockResponse => new MockResponse('{"posts":[]}'));

        try {
            $this->publishTitled($http, $clock);
            self::fail('A post that never shows up may have gone through.');
        } catch (SocialWriteOutcomeUnknownException) {
            self::assertCount(6, self::listingRequests($http));
            self::assertEquals(new \DateTimeImmutable(self::SENT_AT . ' +28 seconds'), $clock->now());
        }
    }

    public function testAPostThatShowsUpOnALaterLookupIsPosted(): void
    {
        $clock = new MockClock(self::SENT_AT);
        $answers = [new MockResponse('{"posts":[]}'), new MockResponse('{"posts":[]}'), self::ownListing()];
        $http = self::instanceHoldingThePost($clock, 8, self::queuedListings($answers));

        $published = $this->publishTitled($http, $clock);

        self::assertSame('https://lemmy.example/post/501', $published->url);
        self::assertCount(3, self::listingRequests($http));
        self::assertEquals(new \DateTimeImmutable(self::SENT_AT . ' +16 seconds'), $clock->now());
    }

    public function testAPostHeldSixSecondsIsLookedUpOnceMoreAtExactlyThirtySeconds(): void
    {
        $this->assertLookups(6, 7, 30);
    }

    public function testAPostHeldSevenSecondsIsNotLookedUpPastThirtySeconds(): void
    {
        $this->assertLookups(7, 6, 27);
    }

    public function testALookupThatFailsIsRetried(): void
    {
        $clock = new MockClock(self::SENT_AT);
        $answers = [new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]), self::ownListing()];
        $http = self::instanceHoldingThePost($clock, 8, self::queuedListings($answers));

        $published = $this->publishTitled($http, $clock);

        self::assertSame('https://lemmy.example/post/501', $published->url);
        self::assertCount(2, self::listingRequests($http));
    }

    public function testAFailedLookupKeepsTheUnknownOutcome(): void
    {
        $clock = new MockClock(self::SENT_AT);
        $http = self::instanceHoldingThePost(
            $clock,
            8,
            static fn (): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        );

        try {
            $this->publishTitled($http, $clock);
            self::fail('A lookup that fails may not hide a lost post.');
        } catch (SocialWriteOutcomeUnknownException) {
            self::assertCount(6, self::listingRequests($http));
        }
    }

    public function testAPostAnsweredWithAServerErrorIsLookedUpToo(): void
    {
        $http = new RoutedHttpClient([
            self::POST => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
            self::LISTING => self::ownListing(),
        ]);

        $published = $this->publishTitled($http);

        self::assertSame('https://lemmy.example/post/501', $published->url);
    }

    public function testATimedOutCommentIsNotLookedUp(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => new MockResponse([''])]);

        try {
            $this->writer($http)->publish($this->account(), $this->replying('42', null, new PostDraftModel('Hello')));
            self::fail('A timed-out comment may have gone through.');
        } catch (SocialWriteOutcomeUnknownException) {
            self::assertCount(1, $http->requests);
        }
    }

    private function publishTitled(RoutedHttpClient $http, ?MockClock $clock = null): PublishedPostModel
    {
        return $this->writer($http, $clock)->publish(
            $this->account(),
            self::linkPost(new PostDraftModel('Hi'), 'Title', 7),
        );
    }

    /**
     * @param list<MockResponse> $answers
     * @return \Closure(): MockResponse
     */
    private static function queuedListings(array &$answers): \Closure
    {
        return static function () use (&$answers): MockResponse {
            return array_shift($answers) ?? new MockResponse('{"posts":[]}');
        };
    }

    private function assertLookups(int $holdSeconds, int $expectedLookups, int $lastLookupSecond): void
    {
        $clock = new MockClock(self::SENT_AT);
        $sentAt = $clock->now();
        $lookedUpAt = [];
        $http = self::instanceHoldingThePost(
            $clock,
            $holdSeconds,
            static function () use ($clock, $sentAt, &$lookedUpAt): MockResponse {
                $lookedUpAt[] = $clock->now()->getTimestamp() - $sentAt->getTimestamp();

                return new MockResponse('{"posts":[]}');
            },
        );

        try {
            $this->publishTitled($http, $clock);
            self::fail('A post that never shows up may have gone through.');
        } catch (SocialWriteOutcomeUnknownException) {
            self::assertCount($expectedLookups, $lookedUpAt);
            self::assertSame($lastLookupSecond, end($lookedUpAt));
        }
    }

    /** @param \Closure(): MockResponse $listing the instance took the post but held its reply */
    private static function instanceHoldingThePost(
        MockClock $clock,
        int $holdSeconds,
        \Closure $listing,
    ): RoutedHttpClient {
        return RoutedHttpClient::answering(
            static function (string $method, string $url) use ($clock, $holdSeconds, $listing): MockResponse {
                if (self::POST === $method . ' ' . $url) {
                    $clock->sleep($holdSeconds);

                    return new MockResponse(['']);
                }

                return self::LISTING === $method . ' ' . $url ? $listing() : Assert::fail('Unrouted request: ' . $url);
            },
        );
    }

    /** @return list<string> */
    private static function listingRequests(RoutedHttpClient $http): array
    {
        return array_values(
            array_filter($http->requestLines(), static fn (string $line): bool => self::LISTING === $line),
        );
    }

    private static function ownListing(): MockResponse
    {
        return new MockResponse(
            '{"posts":[{"post":{"id":501,"name":"Title","community_id":7,"deleted":false,'
            . '"published":"2026-11-25T11:36:09.635002Z","ap_id":"https://lemmy.example/post/501"}}]}',
        );
    }

    private function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    private function replying(string $postId, ?string $commentId, PostDraftModel $draft): PublicationModel
    {
        return new PublicationModel($draft)
            ->withReplyTo(SocialPostModel::onLemmy($this->account(), $postId, $commentId));
    }

    private static function quoting(SocialPostModel $post, PostDraftModel $draft): PublicationModel
    {
        return new PublicationModel($draft)->withEmbed(new QuotedPostEmbedModel($post));
    }

    private static function linkPost(PostDraftModel $draft, ?string $title, ?int $communityId): PublicationModel
    {
        return new PublicationModel($draft)
            ->withEmbed(new LinkEmbedModel(self::ARTICLE, 'An article'))
            ->withLemmy(new LemmyCommunityPostModel($communityId, $title));
    }

    private function writer(RoutedHttpClient $http, ?MockClock $clock = null): LemmyPostWriter
    {
        $lemmy = SocialApiWiring::lemmyAccountApi($http->client());

        return new LemmyPostWriter($lemmy, new LemmyOwnPosts($lemmy), $clock ?? new MockClock(self::SENT_AT));
    }

    private function created(): MockResponse
    {
        return new MockResponse($this->fixture('lemmy/comment-create.json'));
    }

    private static function postCreated(): MockResponse
    {
        return new MockResponse(
            '{"post_view":{"post":{"id":501,"ap_id":"https://lemmy.example/post/501","name":"Title"}}}',
        );
    }
}
