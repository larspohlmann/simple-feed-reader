<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Mastodon\MastodonPostWriter;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostWriterTest extends TestCase
{
    private const string STATUSES = 'POST https://mastodon.example/api/v1/statuses';
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const int MAX_CHARACTERS = 500;
    private const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/120';
    private const string REPLY_URL = 'https://mastodon.example/@alice/120';

    public function testPublishesAStandaloneStatus(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            PublicationModel::fromDraft(new PostDraftModel('Hallo', visibility: 'unlisted', language: 'de')),
        );

        self::assertSame(
            ['status' => 'Hallo', 'visibility' => 'unlisted', 'language' => 'de'],
            json_decode($http->requests[0]['body'], true),
        );
    }

    public function testAReplyThatQuotesCarriesBothIds(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);
        $quoted = SocialPostModel::onMastodon(SocialAccounts::mastodon(), '111');

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::replying(self::post(), new PostDraftModel('Both'))->withEmbed(new QuotedPostEmbedModel($quoted)),
        );

        self::assertSame(
            ['status' => 'Both', 'in_reply_to_id' => '110', 'quoted_status_id' => '111'],
            json_decode($http->requests[0]['body'], true),
        );
    }

    public function testAReplyIsAStatusAnsweringTheTargetWithTheComposersChoices(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $published = $this->writer($http)->publish(SocialAccounts::mastodon(), self::replying(
            self::post(),
            new PostDraftModel(
                '@ann Thanks',
                visibility: 'unlisted',
                contentWarning: 'spoilers',
                language: 'de',
                idempotencyKey: 'key-1',
            ),
        ));

        self::assertSame([
            'status' => '@ann Thanks',
            'in_reply_to_id' => '110',
            'visibility' => 'unlisted',
            'spoiler_text' => 'spoilers',
            'language' => 'de',
        ], json_decode($http->requests[0]['body'], true));
        self::assertContains('Idempotency-Key: key-1', $http->requests[0]['headers']);
        self::assertEquals(new PublishedPostModel(self::REPLY_URI, self::REPLY_URL), $published);
    }

    public function testAQuoteIsAStatusQuotingTheTargetWithTheComposersChoices(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $published = $this->writer($http)->publish(SocialAccounts::mastodon(), self::quoting(
            self::post(),
            new PostDraftModel('Worth reading', visibility: 'unlisted', idempotencyKey: 'key-2'),
        ));

        self::assertSame(
            ['status' => 'Worth reading', 'quoted_status_id' => '110', 'visibility' => 'unlisted'],
            json_decode($http->requests[0]['body'], true),
        );
        self::assertContains('Idempotency-Key: key-2', $http->requests[0]['headers']);
        self::assertEquals(new PublishedPostModel(self::REPLY_URI, self::REPLY_URL), $published);
    }

    public function testASharedLinkIsAStatusOfTheDraftAloneAnsweringAndQuotingNothing(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $published = $this->writer($http)->publish(SocialAccounts::mastodon(), self::sharing(
            'https://example.com/article',
            new PostDraftModel(
                'Read this https://example.com/article',
                visibility: 'unlisted',
                language: 'de',
                idempotencyKey: 'key-3',
            ),
        ));

        self::assertSame(
            ['status' => 'Read this https://example.com/article', 'visibility' => 'unlisted', 'language' => 'de'],
            json_decode($http->requests[0]['body'], true),
        );
        self::assertContains('Idempotency-Key: key-3', $http->requests[0]['headers']);
        self::assertEquals(new PublishedPostModel(self::REPLY_URI, self::REPLY_URL), $published);
    }

    public function testShareAppendsTheUrlAfterABlankLine(): void
    {
        self::assertSame("Title\n\nhttps://e.x/a", $this->sharedStatusOf('Title'));
    }

    public function testShareOfBlankTextPostsTheUrlAlone(): void
    {
        self::assertSame('https://e.x/a', $this->sharedStatusOf(''));
        self::assertSame('https://e.x/a', $this->sharedStatusOf("  \n"));
    }

    public function testShareKeepsTextThatAlreadyCarriesTheUrl(): void
    {
        self::assertSame("Title\n\nhttps://e.x/a", $this->sharedStatusOf("Title\n\nhttps://e.x/a"));
    }

    public function testReplyAndQuoteAppendNothing(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::replying(self::post(), new PostDraftModel('Hi')),
        );
        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::quoting(self::post(), new PostDraftModel('Hi')),
        );

        self::assertSame(['status' => 'Hi', 'in_reply_to_id' => '110'], json_decode($http->requests[0]['body'], true));
        self::assertSame(
            ['status' => 'Hi', 'quoted_status_id' => '110'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    public function testAQuoteTheAuthorsPolicyRefusesIsARefusedPost(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => new MockResponse(
            '{"error":"Validation failed: Quoted status is not allowed"}',
            ['http_code' => Response::HTTP_UNPROCESSABLE_ENTITY],
        )]);

        $this->expectException(SocialPostRefusedException::class);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::quoting(self::post(), new PostDraftModel('Hi')),
        );
    }

    public function testChoicesTheComposerLeftOpenAreNotSent(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::replying(self::post(), new PostDraftModel('Hi', contentWarning: '')),
        );

        self::assertSame(['status' => 'Hi', 'in_reply_to_id' => '110'], json_decode($http->requests[0]['body'], true));
        self::assertEmpty(array_filter(
            $http->requests[0]['headers'],
            static fn (string $header): bool => str_starts_with($header, 'Idempotency-Key'),
        ));
    }

    public function testAReplyNeedsTheWriteStatusesScope(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => new MockResponse(
            '{"error":"This action is outside the authorized scopes"}',
            ['http_code' => Response::HTTP_FORBIDDEN],
        )]);

        try {
            $this->writer($http)->publish(
                SocialAccounts::mastodon(),
                self::replying(self::post(), new PostDraftModel('Hi')),
            );
            self::fail('A token without the scope cannot reply.');
        } catch (SocialTokenScopeMissingException $exception) {
            self::assertSame('write:statuses', $exception->missingScope);
        }
    }

    public function testAnAnswerWithoutAUriIsAddressedByItsUrl(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => new MockResponse(
            json_encode(['id' => '120', 'url' => self::REPLY_URL], \JSON_THROW_ON_ERROR),
        )]);

        $published = $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::replying(self::post(), new PostDraftModel('Hi')),
        );

        self::assertSame(self::REPLY_URL, $published->address);
    }

    public function testAnAnswerWithoutAnAddressIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => new MockResponse('{"id":"120"}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::replying(self::post(), new PostDraftModel('Hi')),
        );
    }

    public function testRefusesTextOverTheServersLimitWithoutSending(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        try {
            $this->writer($http)->publish(
                SocialAccounts::mastodon(),
                PublicationModel::fromDraft(new PostDraftModel(str_repeat('ä', 501))),
            );
            self::fail('A post over the limit is refused.');
        } catch (SocialPostTooLongException $exception) {
            self::assertSame('A Mastodon post on this server holds 500 characters.', $exception->getMessage());
        }

        self::assertSame([], $http->requests);
    }

    public function testTextAtTheServersLimitIsSent(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            PublicationModel::fromDraft(new PostDraftModel(str_repeat('ä', 500))),
        );

        self::assertSame([self::STATUSES], $http->requestLines());
    }

    public function testLongLinksCountAsTwentyThreeCharacters(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);
        $url = 'https://example.com/' . str_repeat('a', 280);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            PublicationModel::fromDraft(new PostDraftModel(str_repeat('a', 476) . ' ' . $url)),
        );

        self::assertSame([self::STATUSES], $http->requestLines());
    }

    public function testTheLinkAppendedToASharedLinkCountsTowardsTheLimit(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->expectException(SocialPostTooLongException::class);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::sharing('https://e.x/a', new PostDraftModel(str_repeat('a', 476))),
        );
    }

    public function testDeletingAStatusReportsWhetherMastodonStillHadIt(): void
    {
        $http = new RoutedHttpClient([
            'DELETE https://mastodon.example/api/v1/statuses/120' => new MockResponse('{}'),
            'DELETE https://mastodon.example/api/v1/statuses/121' => new MockResponse(
                '{}',
                ['http_code' => Response::HTTP_NOT_FOUND],
            ),
        ]);

        self::assertSame(PostDeletion::Deleted, $this->writer($http)->delete(SocialAccounts::mastodon(), '120'));
        self::assertSame(PostDeletion::AlreadyGone, $this->writer($http)->delete(SocialAccounts::mastodon(), '121'));
    }

    private function sharedStatusOf(string $text): string
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->writer($http)->publish(
            SocialAccounts::mastodon(),
            self::sharing('https://e.x/a', new PostDraftModel($text)),
        );

        $status = JsonNodeModel::fromValue(json_decode($http->requests[0]['body'], true))->string('status');
        self::assertNotNull($status);

        return $status;
    }

    private static function replying(SocialPostModel $post, PostDraftModel $draft): PublicationModel
    {
        return PublicationModel::fromDraft($draft)->withReplyTo($post);
    }

    private static function quoting(SocialPostModel $post, PostDraftModel $draft): PublicationModel
    {
        return PublicationModel::fromDraft($draft)->withEmbed(new QuotedPostEmbedModel($post));
    }

    private static function sharing(string $url, PostDraftModel $draft): PublicationModel
    {
        return PublicationModel::fromDraft($draft)->withEmbed(new LinkEmbedModel($url, 'An article'));
    }

    private function writer(RoutedHttpClient $http): MastodonPostWriter
    {
        $instance = (new RoutedHttpClient([self::INSTANCE => new MockResponse(json_encode(
            ['configuration' => ['statuses' => ['max_characters' => self::MAX_CHARACTERS]]],
            \JSON_THROW_ON_ERROR,
        ))]))->client();

        return new MastodonPostWriter(
            SocialApiWiring::mastodonAccountApi($http->client()),
            SocialApiWiring::mastodonStatusLimit($instance),
        );
    }

    private static function post(): SocialPostModel
    {
        return SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110');
    }

    private static function published(): MockResponse
    {
        return new MockResponse(
            json_encode(['id' => '120', 'uri' => self::REPLY_URI, 'url' => self::REPLY_URL], \JSON_THROW_ON_ERROR),
        );
    }
}
