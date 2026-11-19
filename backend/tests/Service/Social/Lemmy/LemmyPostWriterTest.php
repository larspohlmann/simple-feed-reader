<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyApiClient;
use App\Service\Social\Lemmy\LemmyPostWriter;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTitleTooShortException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyPostWriterTest extends TestCase
{
    use ReadsFixtures;

    private const string COMMENT = 'POST https://lemmy.example/api/v3/comment';
    private const string POST = 'POST https://lemmy.example/api/v3/post';
    private const string ARTICLE = 'https://example.com/a';
    private const string PUBLISHED_ADDRESS = 'https://lemmy.example/comment/9001';

    public function testAReplyToAPostIsACommentOnIt(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => $this->created()]);

        $published = $this->writer($http)->reply(
            SocialPostModel::onLemmy($this->account(), '42', null),
            new PostDraftModel('Hello **there**'),
        );

        self::assertSame('{"content":"Hello **there**","post_id":42}', $http->requests[0]['body']);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::LEMMY_JWT, $http->requests[0]['headers']);
        self::assertEquals(new PublishedPostModel(self::PUBLISHED_ADDRESS, self::PUBLISHED_ADDRESS), $published);
    }

    public function testAReplyToACommentNamesItsParent(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => $this->created()]);

        $this->writer($http)->reply(
            SocialPostModel::onLemmy($this->account(), '990', '77'),
            new PostDraftModel('Agreed'),
        );

        self::assertSame('{"content":"Agreed","post_id":990,"parent_id":77}', $http->requests[0]['body']);
    }

    public function testATextOverTenThousandCharactersIsTooLongAndNeverSent(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->writer($http)->reply(
                SocialPostModel::onLemmy($this->account(), '42', null),
                new PostDraftModel(str_repeat('ä', 10001)),
            );
            self::fail('A text over the limit is not sent.');
        } catch (SocialPostTooLongException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testTenThousandCharactersFit(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => $this->created()]);

        $this->writer($http)->reply(
            SocialPostModel::onLemmy($this->account(), '42', null),
            new PostDraftModel(str_repeat('ä', 10000)),
        );

        self::assertCount(1, $http->requests);
    }

    public function testAnAnswerWithoutACommentAddressIsRefused(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => new MockResponse('{"comment_view":{"comment":{"id":1}}}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->reply(SocialPostModel::onLemmy($this->account(), '42', null), new PostDraftModel('Hi'));
    }

    public function testAQuoteIsRefused(): void
    {
        $this->expectExceptionObject(new SocialPostRefusedException('Lemmy takes no quotes.'));

        $this->writer(new RoutedHttpClient([]))->quote(
            SocialPostModel::onLemmy($this->account(), '42', null),
            new PostDraftModel('Hi'),
        );
    }

    public function testALinkIsALinkPostInTheChosenCommunity(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $published = $this->writer($http)->share(
            $this->account(),
            self::ARTICLE,
            new PostDraftModel('Worth a read.', language: 'de', title: 'Title', communityId: 7),
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

    public function testABlankTextLeavesOutTheBody(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->share(
            $this->account(),
            self::ARTICLE,
            new PostDraftModel(" \n", title: 'Title', communityId: 7),
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

        $this->writer($http)->share(
            $this->account(),
            self::ARTICLE,
            new PostDraftModel('', title: 'Title', communityId: 7),
        );
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('refusedLinkDrafts')]
    public function testAnIncompleteOrOverlongLinkIsRefusedAndNeverSent(PostDraftModel $draft, string $exception): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->writer($http)->share($this->account(), self::ARTICLE, $draft);
            self::fail('The link is not sent.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertSame([], $http->requests);
        }
    }

    /** @return iterable<string, array{PostDraftModel, class-string<\Throwable>}> */
    public static function refusedLinkDrafts(): iterable
    {
        yield 'without a community' => [
            new PostDraftModel('Hi', title: 'Title'),
            SocialPostCommunityMissingException::class,
        ];
        yield 'a whitespace title' => [
            new PostDraftModel('Hi', title: "  \n", communityId: 7),
            SocialPostTitleTooShortException::class,
        ];
        yield 'no title' => [new PostDraftModel('Hi', communityId: 7), SocialPostTitleTooShortException::class];
        yield 'a title of two characters once trimmed' => [
            new PostDraftModel('Hi', title: ' äb ', communityId: 7),
            SocialPostTitleTooShortException::class,
        ];
        yield 'a title over two hundred' => [
            new PostDraftModel('Hi', title: str_repeat('ä', 201), communityId: 7),
            SocialPostTooLongException::class,
        ];
        yield 'a body over ten thousand' => [
            new PostDraftModel(str_repeat('ä', 10001), title: 'Title', communityId: 7),
            SocialPostTooLongException::class,
        ];
        yield 'the community is checked before the title' => [
            new PostDraftModel('Hi'),
            SocialPostCommunityMissingException::class,
        ];
    }

    public function testATooShortTitleSaysHowLongATitleMustBe(): void
    {
        $this->expectExceptionObject(
            new SocialPostTitleTooShortException('A Lemmy title needs at least 3 characters.'),
        );

        $this->writer(new RoutedHttpClient([]))->share(
            $this->account(),
            self::ARTICLE,
            new PostDraftModel('', title: 'Hi', communityId: 7),
        );
    }

    public function testATitleOfThreeCharactersFits(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->share(
            $this->account(),
            self::ARTICLE,
            new PostDraftModel('', title: ' Hey ', communityId: 7),
        );

        self::assertSame(
            '{"name":"Hey","url":"https:\/\/example.com\/a","community_id":7}',
            $http->requests[0]['body'],
        );
    }

    public function testATitleOfTwoHundredAndABodyOfTenThousandFit(): void
    {
        $http = new RoutedHttpClient([self::POST => self::postCreated()]);

        $this->writer($http)->share(
            $this->account(),
            self::ARTICLE,
            new PostDraftModel(str_repeat('ä', 10000), title: str_repeat('ä', 200), communityId: 7),
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

    private function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    private function writer(RoutedHttpClient $http): LemmyPostWriter
    {
        $guard = new UrlGuard(new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]), new IpValidator());
        $client = new LemmyApiClient(FetchWiring::patientApiClient($http->client(), $guard));

        return new LemmyPostWriter(new LemmyAccountApi($client, new StoredCredential(SocialAccounts::cipher())));
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
