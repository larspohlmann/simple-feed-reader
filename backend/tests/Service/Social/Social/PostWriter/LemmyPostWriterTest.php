<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostWriter\LemmyPostWriter;
use App\Service\Social\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyPostWriterTest extends TestCase
{
    private const string COMMENT = 'POST https://lemmy.example/api/v3/comment';
    private const string PUBLISHED_ADDRESS = 'https://lemmy.example/comment/9001';

    public function testAReplyToAPostIsACommentOnIt(): void
    {
        $http = new RoutedHttpClient([self::COMMENT => self::created()]);

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
        $http = new RoutedHttpClient([self::COMMENT => self::created()]);

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
        $http = new RoutedHttpClient([self::COMMENT => self::created()]);

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
        $this->expectExceptionObject(new SocialPostRefusedException('Lemmy takes no quotes or link posts.'));

        $this->writer(new RoutedHttpClient([]))->quote(
            SocialPostModel::onLemmy($this->account(), '42', null),
            new PostDraftModel('Hi'),
        );
    }

    public function testALinkPostIsRefused(): void
    {
        $this->expectExceptionObject(new SocialPostRefusedException('Lemmy takes no quotes or link posts.'));

        $this->writer(new RoutedHttpClient([]))->share(
            $this->account(),
            'https://example.com/article',
            new PostDraftModel('Hi'),
        );
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
        $client = new LemmyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new LemmyPostWriter(new LemmyAccountApi($client, new StoredCredential(SocialAccounts::cipher())));
    }

    private static function created(): MockResponse
    {
        $fixture = __DIR__ . '/../../../../Fixtures/lemmy/comment-create.json';

        return new MockResponse((string) file_get_contents($fixture));
    }
}
