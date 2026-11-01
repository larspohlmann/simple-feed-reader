<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostReplier;

use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\BlueskyFacets;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialReplyTooLongException;
use App\Service\Social\Model\PostReferenceModel;
use App\Service\Social\Model\PublishedReplyModel;
use App\Service\Social\Model\ReplyContextModel;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReplier\BlueskyPostReplier;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BlueskyPostReplierTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string REPLY_URI = 'at://did:plc:alice/app.bsky.feed.post/3rep';
    private const string ROOT_URI = 'at://did:plc:carol/app.bsky.feed.post/3root';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.deleteRecord';

    public function testAReplyToATopLevelPostNamesThePostAsRootAndParent(): void
    {
        $http = $this->creating();

        $published = $this->replier($http)->publish(self::post(), new ReplyDraftModel('Thanks #news', language: 'de'));

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
            new PublishedReplyModel(self::REPLY_URI, 'https://bsky.app/profile/did:plc:alice/post/3rep'),
            $published,
        );
    }

    public function testAReplyInsideAThreadNamesTheThreadsRoot(): void
    {
        $http = $this->creating();
        $post = self::post()->inReplyContext(
            new ReplyContextModel(threadRoot: new PostReferenceModel(self::ROOT_URI, 'bafy-root')),
        );

        $this->replier($http)->publish($post, new ReplyDraftModel('Yes'));

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

        $this->replier($http)->publish(self::post(), new ReplyDraftModel(str_repeat($family, 300)));

        $this->expectException(SocialReplyTooLongException::class);

        try {
            $this->replier($http)->publish(self::post(), new ReplyDraftModel(str_repeat($family, 301)));
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testAnAnswerWithoutTheNewPostsUriIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse('{}'),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->replier($http)->publish(self::post(), new ReplyDraftModel('Hi'));
    }

    public function testDeletingAReplyDeletesItsPostRecord(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => new MockResponse('{}'),
        ]);
        $own = SocialPostModel::onBluesky(SocialApiWiring::blueskyAccount(), self::REPLY_URI, 'bafy-rep');

        $this->replier($http)->delete($own);

        self::assertSame(
            ['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.post', 'rkey' => '3rep'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    public function testAnAddressThatNamesNoPostRecordCannotBeDeleted(): void
    {
        $own = SocialPostModel::onBluesky(SocialApiWiring::blueskyAccount(), 'at://did:plc:alice/x/y', 'bafy');

        $this->expectException(SocialPostRefusedException::class);

        $this->replier(new RoutedHttpClient([]))->delete($own);
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
            self::CREATE_RECORD => new MockResponse(
                json_encode(['uri' => self::REPLY_URI, 'cid' => 'bafy-rep'], \JSON_THROW_ON_ERROR),
            ),
        ]);
    }

    private function replier(RoutedHttpClient $http): BlueskyPostReplier
    {
        return new BlueskyPostReplier(
            new BlueskyRecords(
                SocialApiWiring::blueskySessions($http->client()),
                new NaiveUtcClock(new MockClock(new \DateTimeImmutable('2026-10-13T08:30:00.123+02:00'))),
            ),
            new BlueskyFacets(SocialApiWiring::blueskyIdentityResolver($http->client())),
        );
    }

    private static function post(): SocialPostModel
    {
        return SocialPostModel::onBluesky(SocialApiWiring::blueskyAccount(), self::POST_URI, 'bafy-post');
    }
}
