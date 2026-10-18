<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Social\BlueskyThreadReplies;
use App\Service\Social\Pass\ReplyNode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class BlueskyThreadRepliesTest extends TestCase
{
    private const string POST = 'at://did:plc:op/app.bsky.feed.post/root';
    private const string WEB = 'https://bsky.app/profile/';

    public function testOnlyShownRepliesBecomeTheTree(): void
    {
        $replies = $this->replies();

        self::assertSame(
            [
                self::WEB . 'did:plc:ann/post/r1',
                self::WEB . 'did:plc:cy/post/r3',
                self::WEB . 'did:plc:di/post/r4',
                self::WEB . 'did:plc:ed/post/r5',
            ],
            self::urlsOf($replies),
        );
        self::assertSame([self::WEB . 'did:plc:op/post/r1a'], self::urlsOf($replies[0]->replies));
        self::assertSame([self::WEB . 'did:plc:bo/post/r1a1'], self::urlsOf($replies[0]->replies[0]->replies));
    }

    public function testAMutedAuthorsReplyGoesWithItsAnswers(): void
    {
        $urls = self::allUrlsOf($this->replies());

        self::assertNotContains(self::WEB . 'did:plc:muted/post/r2', $urls);
        self::assertNotContains(self::WEB . 'did:plc:bo/post/r2a', $urls);
    }

    public function testABlockingOrBlockedAuthorsReplyIsLeftOut(): void
    {
        $answer = self::threadOf([
            self::replyBy('did:plc:blocks', ['blockedBy' => true]),
            self::replyBy('did:plc:blocked', ['blocking' => 'at://did:plc:viewer/app.bsky.graph.block/b1']),
            self::replyBy('did:plc:shown', ['blocking' => null]),
        ]);

        self::assertSame([self::WEB . 'did:plc:shown/post/reply'], self::urlsOf($this->replies($answer)));
    }

    public function testASelfReplyRendersLikeItsThreadComment(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/Bluesky/self-thread-replies.json');
        Assert::assertIsString($contents);
        $thread = JsonNodeModel::of(json_decode($contents, true))->node('thread');
        $reader = new BlueskyThreadReplies(new PostEmbedRenderer(), new EntrySanitizer(new TrailingBlankRemover()));
        $node = $reader->of($thread->node('post')->string('uri') ?? '', $contents)[0];

        $selfReply = $reader->selfReplyOf($thread->nodes('replies')[0]->node('post'));

        self::assertEquals($node->comment(), $selfReply);
        self::assertTrue($selfReply->byEntryAuthor);
    }

    public function testAReplyNamesItsAuthor(): void
    {
        $author = $this->replies()[0]->comment()->author;

        self::assertEquals(
            new CommentAuthorModel(
                'Ann',
                '@ann.test',
                self::WEB . 'ann.test',
                'https://cdn.bsky.app/img/avatar/plain/did:plc:ann/bafyavatar@jpeg',
            ),
            $author,
        );
    }

    public function testAnAuthorWithoutADisplayNameGoesByTheirHandle(): void
    {
        $author = $this->replies()[0]->replies[0]->replies[0]->comment()->author;

        self::assertSame('bo.test', $author->name);
        self::assertNull($author->avatarUrl);
    }

    public function testAReplyKeepsItsTime(): void
    {
        self::assertEquals(
            new \DateTimeImmutable('2026-10-16T09:00:00Z'),
            $this->replies()[0]->comment()->publishedAt,
        );
    }

    public function testTheOriginalPostersReplyIsMarked(): void
    {
        $replies = $this->replies();

        self::assertTrue($replies[0]->replies[0]->comment()->byEntryAuthor);
        self::assertFalse($replies[0]->comment()->byEntryAuthor);
    }

    public function testANodeKnowsItsAuthorshipAndUrlWithoutBuildingTheComment(): void
    {
        $replies = $this->replies();

        self::assertTrue($replies[0]->replies[0]->byEntryAuthor);
        self::assertSame(self::WEB . 'did:plc:op/post/r1a', $replies[0]->replies[0]->url);
        self::assertFalse($replies[0]->byEntryAuthor);
    }

    public function testAPostNamingItsAuthorByHandleStillMarksTheirReply(): void
    {
        $replies = $this->replies(post: 'at://op.test/app.bsky.feed.post/root');

        self::assertTrue($replies[0]->replies[0]->comment()->byEntryAuthor);
    }

    public function testTextKeepsItsLinks(): void
    {
        $html = $this->replies()[0]->comment()->html;

        self::assertStringContainsString('<a href="https://example.com/a"', $html);
        self::assertStringContainsString('Read ', $html);
    }

    public function testAReplysMarkupStaysTextAndItsLinksAreHardened(): void
    {
        $reply = self::replyBy('did:plc:shown', []);
        $reply['post'] = [
            'uri' => 'at://did:plc:shown/app.bsky.feed.post/reply',
            'author' => ['did' => 'did:plc:shown', 'handle' => 'shown.test'],
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => '<script>alert(1)</script> <b onclick="steal()">see</b> https://example.com/a',
                'facets' => [[
                    'index' => ['byteStart' => 55, 'byteEnd' => 76],
                    'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a']],
                ]],
            ],
        ];

        $html = $this->replies(self::threadOf([$reply]))[0]->comment()->html;

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b onclick', $html);
        self::assertStringContainsString(
            '<a href="https://example.com/a" rel="noopener noreferrer" target="_blank">',
            $html,
        );
    }

    public function testImagesBecomeMedia(): void
    {
        $comment = $this->replies()[0]->comment();

        self::assertSame(VisualMediaKind::Image->value, $comment->embeds->media[0]->kind);
        self::assertStringNotContainsString('<img', $comment->html);
    }

    public function testAQuotedPostBecomesTheQuote(): void
    {
        self::assertSame(
            self::WEB . 'did:plc:quoted/post/quoted1',
            self::commentAt($this->replies(), 1)->embeds->quote?->url,
        );
    }

    public function testAnExternalEmbedBecomesTheLinkCard(): void
    {
        self::assertSame(
            'The background story',
            self::commentAt($this->replies(), 2)->embeds->linkCard?->title,
        );
    }

    public function testAVideoBecomesAPlayableMedium(): void
    {
        $medium = self::commentAt($this->replies(), 3)->embeds->media[0];

        self::assertSame(VisualMediaKind::Video->value, $medium->kind);
        self::assertSame(
            'https://video.bsky.app/watch/did%3Aplc%3Aed/bafyvideo/thumbnail.jpg',
            $medium->previewImageUrl,
        );
    }

    public function testALinkWithoutACardStaysInTheText(): void
    {
        $reply = self::replyBy('did:plc:shown', [], [
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://example.org/a?b&c'],
        ]);

        $comment = $this->replies(self::threadOf([$reply]))[0]->comment();

        self::assertSame(
            '<p>A reply</p><p><a href="https://example.org/a?b&amp;c" rel="noopener noreferrer" target="_blank">'
                . 'https://example.org/a?b&amp;c</a></p>',
            $comment->html,
        );
        self::assertNull($comment->embeds->linkCard);
    }

    public function testAGifReplyIsAnAnimatedImageNotALinkCard(): void
    {
        $reply = self::replyBy('did:plc:shown', [], [
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://media.tenor.com/abc/clap.gif?hh=280&ww=498',
                'title' => 'Clapping',
                'description' => 'ALT: Clapping',
                'thumb' => 'https://cdn.bsky.app/clap.jpg',
            ],
        ]);

        $embeds = $this->replies(self::threadOf([$reply]))[0]->comment()->embeds;

        self::assertSame(
            ['https://media.tenor.com/abc/clap.gif?hh=280&ww=498', VisualMediaKind::Image->value, 498, 280],
            [$embeds->media[0]->url, $embeds->media[0]->kind, $embeds->media[0]->width, $embeds->media[0]->height],
        );
        self::assertSame(
            ['https://cdn.bsky.app/clap.jpg', 'Clapping'],
            [$embeds->media[0]->previewImageUrl, $embeds->media[0]->alt],
        );
        self::assertNull($embeds->linkCard);
    }

    public function testAReplyWithoutEmbedsHasNone(): void
    {
        $embeds = $this->replies()[0]->replies[0]->comment()->embeds;

        self::assertSame([], $embeds->media);
        self::assertNull($embeds->linkCard);
        self::assertNull($embeds->quote);
    }

    public function testAReplyIsThePostALikeGoesTo(): void
    {
        $reply = self::replyBy('did:plc:ann', [], [], [
            'likeCount' => 3,
            'repostCount' => 0,
            'replyCount' => 1,
            'viewer' => ['like' => 'at://did:plc:viewer/app.bsky.feed.like/1'],
        ]);

        $post = self::commentAt($this->replies(self::threadOf([$reply])), 0)->post;

        self::assertNotNull($post);
        self::assertSame('at://did:plc:ann/app.bsky.feed.post/reply', $post->address);
        self::assertSame(3, $post->counts->likes);
        self::assertTrue($post->isLiked);
    }

    public function testAnAnswerWithoutAThreadIsUnavailable(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->replies('{"error":"x"}');
    }

    /** @return list<ReplyNode> */
    private function replies(?string $answer = null, string $post = self::POST): array
    {
        $reader = new BlueskyThreadReplies(new PostEmbedRenderer(), new EntrySanitizer(new TrailingBlankRemover()));

        return $reader->of($post, $answer ?? self::fixture());
    }

    private static function fixture(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/social/bluesky-post-thread.json');
        Assert::assertIsString($contents);

        return $contents;
    }

    /**
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $embed
     * @param array<string, mixed> $postFields
     *
     * @return array<string, mixed>
     */
    private static function replyBy(string $did, array $viewer, array $embed = [], array $postFields = []): array
    {
        return [
            '$type' => 'app.bsky.feed.defs#threadViewPost',
            'post' => [
                'uri' => 'at://' . $did . '/app.bsky.feed.post/reply',
                'author' => ['did' => $did, 'handle' => 'someone.test', 'viewer' => $viewer],
                'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'A reply'],
                'embed' => $embed,
                ...$postFields,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $replies */
    private static function threadOf(array $replies): string
    {
        return json_encode(
            ['thread' => ['$type' => 'app.bsky.feed.defs#threadViewPost', 'replies' => $replies]],
            \JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<ReplyNode> $replies */
    private static function commentAt(array $replies, int $index): EntryCommentModel
    {
        return $replies[$index]->comment();
    }

    /**
     * @param list<ReplyNode> $replies
     *
     * @return list<?string>
     */
    private static function urlsOf(array $replies): array
    {
        return array_map(static fn (ReplyNode $reply): ?string => $reply->comment()->url, $replies);
    }

    /**
     * @param list<ReplyNode> $replies
     *
     * @return list<?string>
     */
    private static function allUrlsOf(array $replies): array
    {
        $urls = [];
        foreach ($replies as $reply) {
            $urls = [...$urls, $reply->comment()->url, ...self::allUrlsOf($reply->replies)];
        }

        return $urls;
    }
}
