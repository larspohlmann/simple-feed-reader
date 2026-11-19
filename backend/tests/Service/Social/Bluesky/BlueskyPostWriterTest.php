<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Entity\LinkCardContent;
use App\Service\Outbound\Image\ImageResizer\ImageResizerInterface;
use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Social\Bluesky\BlueskyFacets;
use App\Service\Social\Bluesky\BlueskyExternalEmbeds;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PostReferenceModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\ResponseContextModel;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Bluesky\BlueskyPostWriter;
use App\Service\Social\Social\SharedPageCards;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\StubGuardedImageDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyPostWriterTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string REPLY_URI = 'at://did:plc:alice/app.bsky.feed.post/3rep';
    private const string ROOT_URI = 'at://did:plc:carol/app.bsky.feed.post/3root';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.deleteRecord';
    private const string GET_RECORD = 'GET https://pds.example.test/xrpc/com.atproto.repo.getRecord';
    private const string ARTICLE_URL = 'https://example.com/article';
    private const string IDEMPOTENCY_KEY = 'composer-key-1';

    private ArrayAdapter $postKeyCache;

    protected function setUp(): void
    {
        $this->postKeyCache = new ArrayAdapter();
    }

    public function testAReplyToATopLevelPostNamesThePostAsRootAndParent(): void
    {
        $http = $this->creating();

        $published = $this->writer($http)->reply(self::post(), new PostDraftModel('Thanks #news', language: 'de'));

        self::assertSame([
            'repo' => 'did:plc:alice',
            'collection' => 'app.bsky.feed.post',
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Thanks #news',
                'reply' => [
                    'root' => ['uri' => self::POST_URI, 'cid' => 'bafy-post'],
                    'parent' => ['uri' => self::POST_URI, 'cid' => 'bafy-post'],
                ],
                'facets' => [[
                    'index' => ['byteStart' => 7, 'byteEnd' => 12],
                    'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'news']],
                ]],
                'langs' => ['de'],
                'createdAt' => '2026-10-13T06:30:00.123Z',
            ],
        ], json_decode($http->requests[1]['body'], true));
        self::assertEquals(
            new PublishedPostModel(self::REPLY_URI, 'https://bsky.app/profile/did:plc:alice/post/3rep'),
            $published,
        );
    }

    public function testAReplyInsideAThreadNamesTheThreadsRoot(): void
    {
        $http = $this->creating();
        $post = self::post()->inResponseContext(
            new ResponseContextModel(threadRoot: new PostReferenceModel(self::ROOT_URI, 'bafy-root')),
        );

        $this->writer($http)->reply($post, new PostDraftModel('Yes'));

        $record = self::recordSent($http);
        self::assertSame([
            'root' => ['uri' => self::ROOT_URI, 'cid' => 'bafy-root'],
            'parent' => ['uri' => self::POST_URI, 'cid' => 'bafy-post'],
        ], $record['reply']);
        self::assertArrayNotHasKey('facets', $record);
        self::assertArrayNotHasKey('langs', $record);
    }

    public function testThreeHundredGraphemesFitAndOneMoreIsRefusedBeforeAnyCall(): void
    {
        $http = $this->creating();
        $family = '👨‍👩‍👧';

        $this->writer($http)->reply(self::post(), new PostDraftModel(str_repeat($family, 300)));

        $this->expectException(SocialPostTooLongException::class);

        try {
            $this->writer($http)->reply(self::post(), new PostDraftModel(str_repeat($family, 301)));
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testAQuoteEmbedsThePostItQuotesAndAnswersNothing(): void
    {
        $http = $this->creating();
        $post = self::post()->inResponseContext(
            new ResponseContextModel(threadRoot: new PostReferenceModel(self::ROOT_URI, 'bafy-root')),
        );

        $published = $this->writer($http)->quote($post, new PostDraftModel('Look #news', language: 'en'));

        $record = self::recordSent($http);
        self::assertSame(
            ['$type' => 'app.bsky.embed.record', 'record' => ['uri' => self::POST_URI, 'cid' => 'bafy-post']],
            $record['embed'],
        );
        self::assertArrayNotHasKey('reply', $record);
        self::assertSame('Look #news', $record['text']);
        self::assertSame(['en'], $record['langs']);
        self::assertIsArray($record['facets']);
        self::assertCount(1, $record['facets']);
        self::assertSame(self::REPLY_URI, $published->address);
    }

    public function testAQuoteHoldsThreeHundredGraphemesToo(): void
    {
        $this->expectException(SocialPostTooLongException::class);

        $this->writer(new RoutedHttpClient([]))->quote(self::post(), new PostDraftModel(str_repeat('a', 301)));
    }

    public function testASharedLinkIsAPostWithTheLinksCardAndAnswersNothing(): void
    {
        $http = $this->creating();

        $published = $this->writer($http)->share(
            SocialApiWiring::blueskyAccount(),
            self::ARTICLE_URL,
            new PostDraftModel('Read this #news https://example.com/article', language: 'en'),
        );

        $record = self::recordSent($http);
        self::assertSame('Read this #news https://example.com/article', $record['text']);
        self::assertSame([
            '$type' => 'app.bsky.embed.external',
            'external' => [
                'uri' => 'https://example.com/article',
                'title' => 'An article',
                'description' => 'A summary',
            ],
        ], $record['embed']);
        self::assertSame(['en'], $record['langs']);
        self::assertIsArray($record['facets']);
        self::assertArrayNotHasKey('reply', $record);
        self::assertSame(self::REPLY_URI, $published->address);
    }

    public function testATooLongSharedLinkIsRefusedBeforeItsPageIsRead(): void
    {
        $pageCards = $this->createMock(PageCardReaderInterface::class);
        $pageCards->expects($this->never())->method('read');

        $this->expectException(SocialPostTooLongException::class);

        $this->writerReadingPagesWith(new RoutedHttpClient([]), $pageCards)
            ->share(SocialApiWiring::blueskyAccount(), self::ARTICLE_URL, new PostDraftModel(str_repeat('a', 301)));
    }

    public function testAnAnswerWithoutTheNewPostsUriIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse('{}'),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->reply(self::post(), new PostDraftModel('Hi'));
    }

    public function testDeletingAPostDeletesItsRecordInTheAccountsRepo(): void
    {
        $http = $this->deleting(new MockResponse('{"commit":{"cid":"bafy-commit","rev":"3rev"}}'));

        $deletion = $this->writer($http)->delete(SocialApiWiring::blueskyAccount(), self::REPLY_URI);

        self::assertSame(PostDeletion::Deleted, $deletion);
        self::assertSame(
            ['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.post', 'rkey' => '3rep'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    #[DataProvider('goneAnswers')]
    public function testARecordBlueskyNoLongerHasIsReportedGone(MockResponse $answer): void
    {
        $deletion = $this->writer($this->deleting($answer))->delete(SocialApiWiring::blueskyAccount(), self::REPLY_URI);

        self::assertSame(PostDeletion::AlreadyGone, $deletion);
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function goneAnswers(): iterable
    {
        yield 'an answer without a commit' => [new MockResponse('{}')];
        yield 'not found' => [new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND])];
    }

    public function testAnAddressThatNamesNoPostRecordCannotBeDeleted(): void
    {
        $this->expectException(SocialPostRefusedException::class);

        $this->writer(new RoutedHttpClient([]))->delete(SocialApiWiring::blueskyAccount(), 'at://did:plc:alice/x/y');
    }

    public function testFirstSendCreatesUnderTheRememberedKey(): void
    {
        $http = $this->creating();

        $published = $this->writer($http)->reply(self::post(), self::keyedDraft());

        $body = self::createRecordBody($http);
        self::assertSame($this->rememberedRecordKey(), $body['rkey']);
        self::assertSame([self::SESSION, self::CREATE_RECORD], $http->requestLines());
        self::assertSame(self::REPLY_URI, $published->address);
    }

    public function testRetryReturnsThePostAlreadyCreated(): void
    {
        $recordKey = $this->rememberedRecordKey();
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::getRecordLine($recordKey) => self::recordFound($recordKey),
        ]);

        $published = $this->writer($http)->reply(self::post(), self::keyedDraft());

        self::assertEquals(new PublishedPostModel(
            self::ownPostUri($recordKey),
            'https://bsky.app/profile/did:plc:alice/post/' . $recordKey,
        ), $published);
        self::assertSame([self::SESSION, self::getRecordLine($recordKey)], $http->requestLines());
    }

    public function testARetryThatFindsItsSharedPostNeverBuildsTheLinkCard(): void
    {
        $recordKey = $this->rememberedRecordKey();
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::getRecordLine($recordKey) => self::recordFound($recordKey),
        ]);
        $pageCards = $this->createMock(PageCardReaderInterface::class);
        $pageCards->expects($this->never())->method('read');

        $published = $this->writerReadingPagesWith($http, $pageCards)->share(
            SocialApiWiring::blueskyAccount(),
            self::ARTICLE_URL,
            new PostDraftModel('Hi @bob.bsky.social', idempotencyKey: self::IDEMPOTENCY_KEY),
        );

        self::assertSame(self::ownPostUri($recordKey), $published->address);
        self::assertSame([self::SESSION, self::getRecordLine($recordKey)], $http->requestLines());
    }

    public function testRetryCreatesWhenTheFirstAttemptNeverLanded(): void
    {
        $recordKey = $this->rememberedRecordKey();
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::getRecordLine($recordKey) => self::recordNotFound(),
            self::CREATE_RECORD => self::created(),
        ]);

        $published = $this->writer($http)
            ->share(SocialApiWiring::blueskyAccount(), self::ARTICLE_URL, self::keyedDraft());

        self::assertSame($recordKey, self::createRecordBody($http)['rkey']);
        self::assertSame(self::REPLY_URI, $published->address);
    }

    public function testRaceOnCreateResolvesToTheExistingPost(): void
    {
        $http = RoutedHttpClient::answering(fn (string $method, string $url): MockResponse => match (true) {
            self::SESSION === $method . ' ' . $url => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD === $method . ' ' . $url => self::duplicateRecordKey(),
            default => self::recordFound($this->rememberedRecordKey()),
        });

        $published = $this->writer($http)->quote(self::post(), self::keyedDraft());

        $recordKey = $this->rememberedRecordKey();
        self::assertSame(self::ownPostUri($recordKey), $published->address);
        self::assertSame(
            [self::SESSION, self::CREATE_RECORD, self::getRecordLine($recordKey)],
            $http->requestLines(),
        );
    }

    public function testAFailedCreateWithNothingBehindItIsRethrown(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            self::SESSION === $method . ' ' . $url => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD === $method . ' ' . $url => self::duplicateRecordKey(),
            default => self::recordNotFound(),
        });

        $this->expectException(SocialWriteOutcomeUnknownException::class);
        $this->expectExceptionMessage(
            'Bluesky had trouble with this request, so it may or may not have gone through. '
            . 'Check before trying again.',
        );

        $this->writer($http)->reply(self::post(), self::keyedDraft());
    }

    public function testAnUnknownCreateOutcomeOutlivesALookupBlueskyCannotAnswer(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => match (true) {
            self::SESSION === $method . ' ' . $url => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            default => self::serviceUnavailable(),
        });

        try {
            $this->writer($http)->reply(self::post(), self::keyedDraft());
            self::fail('The create should have failed.');
        } catch (SocialWriteOutcomeUnknownException $exception) {
            self::assertSame('Bluesky answered 503.', $exception->getPrevious()?->getMessage());
        }

        $getRecord = self::getRecordLine($this->rememberedRecordKey());
        self::assertSame(
            [self::SESSION, self::CREATE_RECORD, $getRecord, $getRecord, $getRecord],
            $http->requestLines(),
        );
    }

    public function testARefusedCreateIsNotLookedUp(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse(
                '{"error":"InvalidRequest","message":"Invalid app.bsky.feed.post record"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);

        $this->expectException(SocialPostRefusedException::class);

        try {
            $this->writer($http)->reply(self::post(), self::keyedDraft());
        } finally {
            self::assertSame([self::SESSION, self::CREATE_RECORD], $http->requestLines());
        }
    }

    public function testALookupBlueskyCannotAnswerIsAnUnreachableNetwork(): void
    {
        $recordKey = $this->rememberedRecordKey();
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::getRecordLine($recordKey) => new MockResponse(
                '{"error":"InvalidRequest","message":"Could not locate record"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->reply(self::post(), self::keyedDraft());
    }

    public function testAFoundRecordWithoutItsAddressIsAnUnreachableNetwork(): void
    {
        $recordKey = $this->rememberedRecordKey();
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::getRecordLine($recordKey) => new MockResponse('{}'),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->writer($http)->reply(self::post(), self::keyedDraft());
    }

    public function testWithoutAKeyNothingChanges(): void
    {
        $http = $this->creating();

        $this->writer($http)->reply(self::post(), new PostDraftModel('Hi'));

        self::assertArrayNotHasKey('rkey', self::createRecordBody($http));
        self::assertSame([self::SESSION, self::CREATE_RECORD], $http->requestLines());
    }

    private function rememberedRecordKey(): string
    {
        return SocialApiWiring::blueskyPostKeys($this->postKeyCache)
            ->recordKeyFor(SocialApiWiring::blueskyAccount(), self::IDEMPOTENCY_KEY)
            ->recordKey;
    }

    private static function keyedDraft(): PostDraftModel
    {
        return new PostDraftModel('Hi', idempotencyKey: self::IDEMPOTENCY_KEY);
    }

    private static function ownPostUri(string $recordKey): string
    {
        return 'at://did:plc:alice/app.bsky.feed.post/' . $recordKey;
    }

    private static function getRecordLine(string $recordKey): string
    {
        return self::GET_RECORD . '?repo=did%3Aplc%3Aalice&collection=app.bsky.feed.post&rkey=' . $recordKey;
    }

    private static function recordFound(string $recordKey): MockResponse
    {
        return new MockResponse(json_encode(
            ['uri' => self::ownPostUri($recordKey), 'cid' => 'bafy-own', 'value' => ['text' => 'Hi']],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private static function recordNotFound(): MockResponse
    {
        return new MockResponse(
            '{"error":"RecordNotFound","message":"Could not locate record"}',
            ['http_code' => Response::HTTP_BAD_REQUEST],
        );
    }

    private static function serviceUnavailable(): MockResponse
    {
        return new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
    }

    private static function duplicateRecordKey(): MockResponse
    {
        return new MockResponse(
            '{"error":"InternalServerError","message":"Internal Server Error"}',
            ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR],
        );
    }

    /** @return array<mixed> */
    private static function createRecordBody(RoutedHttpClient $http): array
    {
        foreach ($http->requests as $request) {
            if (self::CREATE_RECORD === $request['method'] . ' ' . $request['url']) {
                $body = json_decode($request['body'], true);
                self::assertIsArray($body);

                return $body;
            }
        }

        self::fail('No record was created.');
    }

    private function deleting(MockResponse $answer): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => $answer,
        ]);
    }

    /** @return array<mixed> */
    private static function recordSent(RoutedHttpClient $http): array
    {
        $body = json_decode($http->requests[1]['body'], true);
        self::assertIsArray($body);
        self::assertIsArray($body['record']);

        return $body['record'];
    }

    private function creating(): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::created(),
        ]);
    }

    private static function created(): MockResponse
    {
        return new MockResponse(json_encode(['uri' => self::REPLY_URI, 'cid' => 'bafy-rep'], \JSON_THROW_ON_ERROR));
    }

    private function writer(RoutedHttpClient $http): BlueskyPostWriter
    {
        $pageCards = $this->createStub(PageCardReaderInterface::class);
        $pageCards->method('read')->willReturn(new PageCardModel(
            new LinkCardContent(self::ARTICLE_URL, 'An article', 'A summary', null, 'example.com'),
            null,
        ));

        return $this->writerReadingPagesWith($http, $pageCards);
    }

    private function writerReadingPagesWith(
        RoutedHttpClient $http,
        PageCardReaderInterface $pageCards,
    ): BlueskyPostWriter {
        return new BlueskyPostWriter(
            SocialApiWiring::blueskyRecords($http->client()),
            new BlueskyFacets(SocialApiWiring::blueskyIdentityResolver($http->client())),
            new BlueskyExternalEmbeds(
                new SharedPageCards($pageCards, new NullLogger()),
                new StubGuardedImageDownloader(),
                $this->createStub(ImageResizerInterface::class),
                SocialApiWiring::blueskySessions($http->client()),
                new NullLogger(),
            ),
            SocialApiWiring::blueskyPostKeys($this->postKeyCache),
        );
    }

    private static function post(): SocialPostModel
    {
        return SocialPostModel::onBluesky(SocialApiWiring::blueskyAccount(), self::POST_URI, 'bafy-post');
    }
}
