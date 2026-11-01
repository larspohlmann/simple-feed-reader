<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostWriter;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostWriter\MastodonPostWriter;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostWriterTest extends TestCase
{
    private const string STATUSES = 'POST https://mastodon.example/api/v1/statuses';
    private const string REPLY_URI = 'https://mastodon.example/users/alice/statuses/120';
    private const string REPLY_URL = 'https://mastodon.example/@alice/120';

    public function testAReplyIsAStatusAnsweringTheTargetWithTheComposersChoices(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $published = $this->writer($http)->reply(self::post(), new PostDraftModel(
            '@ann Thanks',
            visibility: 'unlisted',
            contentWarning: 'spoilers',
            language: 'de',
            idempotencyKey: 'key-1',
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

        $published = $this->writer($http)->quote(self::post(), new PostDraftModel(
            'Worth reading',
            visibility: 'unlisted',
            idempotencyKey: 'key-2',
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

        $published = $this->writer($http)->share(
            SocialAccounts::mastodon(),
            new SharedLinkModel('https://example.com/article', 'An article', 'A summary', null),
            new PostDraftModel(
                'Read this https://example.com/article',
                visibility: 'unlisted',
                language: 'de',
                idempotencyKey: 'key-3',
            ),
        );

        self::assertSame(
            ['status' => 'Read this https://example.com/article', 'visibility' => 'unlisted', 'language' => 'de'],
            json_decode($http->requests[0]['body'], true),
        );
        self::assertContains('Idempotency-Key: key-3', $http->requests[0]['headers']);
        self::assertEquals(new PublishedPostModel(self::REPLY_URI, self::REPLY_URL), $published);
    }

    public function testAQuoteTheAuthorsPolicyRefusesIsARefusedPost(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => new MockResponse(
            '{"error":"Validation failed: Quoted status is not allowed"}',
            ['http_code' => Response::HTTP_UNPROCESSABLE_ENTITY],
        )]);

        $this->expectException(SocialPostRefusedException::class);

        $this->writer($http)->quote(self::post(), new PostDraftModel('Hi'));
    }

    public function testChoicesTheComposerLeftOpenAreNotSent(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => self::published()]);

        $this->writer($http)->reply(self::post(), new PostDraftModel('Hi', contentWarning: ''));

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
            $this->writer($http)->reply(self::post(), new PostDraftModel('Hi'));
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

        $published = $this->writer($http)->reply(self::post(), new PostDraftModel('Hi'));

        self::assertSame(self::REPLY_URL, $published->address);
    }

    public function testAnAnswerWithoutAnAddressIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([self::STATUSES => new MockResponse('{"id":"120"}')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->reply(self::post(), new PostDraftModel('Hi'));
    }

    public function testDeletingAReplyDeletesItsStatusAndAGoneOneCountsAsDeleted(): void
    {
        $http = new RoutedHttpClient([
            'DELETE https://mastodon.example/api/v1/statuses/120' => new MockResponse('{}'),
            'DELETE https://mastodon.example/api/v1/statuses/121' => new MockResponse(
                '{}',
                ['http_code' => Response::HTTP_NOT_FOUND],
            ),
        ]);

        $this->writer($http)->delete(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '120'));
        $this->writer($http)->delete(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '121'));

        self::assertCount(2, $http->requests);
    }

    private function writer(RoutedHttpClient $http): MastodonPostWriter
    {
        return new MastodonPostWriter(SocialApiWiring::mastodonAccountApi($http->client()));
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
