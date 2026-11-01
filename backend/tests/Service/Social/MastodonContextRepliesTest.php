<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Service\Social\MastodonContextReplies;
use App\Service\Social\Pass\ReplyNode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class MastodonContextRepliesTest extends TestCase
{
    private const string POST = 'https://mastodon.social/@Gargron/110';

    public function testRepliesNestByTheStatusTheyAnswer(): void
    {
        $replies = $this->replies();

        self::assertSame(
            ['https://hachyderm.io/@ann/200', 'https://fosstodon.org/@cy/204'],
            self::urlsOf($replies),
        );
        self::assertSame(['https://mastodon.social/@Gargron/201'], self::urlsOf($replies[0]->replies));
        self::assertSame([], $replies[1]->replies);
    }

    public function testASelfReplyRendersLikeItsThreadComment(): void
    {
        $descendants = JsonNodeModel::of(json_decode(self::fixture(), true))->nodes('descendants');
        $status = array_values(array_filter(
            $descendants,
            static fn (JsonNodeModel $candidate): bool => $candidate->string('id') === '201',
        ))[0];
        $reader = new MastodonContextReplies(new EntrySanitizer(new TrailingBlankRemover()));
        $node = $this->replies()[0]->replies[0];

        $selfReply = $reader->selfReplyOf($status);

        self::assertEquals($node->comment(), $selfReply);
        self::assertTrue($selfReply->byEntryAuthor);
    }

    public function testAReplyIsThePostALikeGoesTo(): void
    {
        $answer = self::contextOf([self::statusOf('300', '110', [
            'favourites_count' => 4,
            'reblogs_count' => 1,
            'replies_count' => 0,
            'favourited' => true,
        ])]);

        $post = $this->replies($answer)[0]->comment()->post;

        self::assertNotNull($post);
        self::assertSame('https://example.social/users/someone/statuses/300', $post->address);
        self::assertSame(4, $post->counts->likes);
        self::assertTrue($post->viewer->isLiked);
    }

    public function testANonPublicReplyGoesWithItsAnswers(): void
    {
        $urls = self::allUrlsOf($this->replies());

        self::assertNotContains('https://mastodon.social/@followers/202', $urls);
        self::assertNotContains('https://mastodon.social/@bo/203', $urls);
    }

    public function testAReplyToAStatusTheInstanceLeftOutIsNotPromoted(): void
    {
        $answer = self::contextOf([
            self::statusOf('300', '110', []),
            self::statusOf('301', '299', []),
            self::statusOf('302', '301', []),
        ]);

        self::assertSame(['https://example.social/@someone/300'], self::allUrlsOf($this->replies($answer)));
    }

    public function testTheAccountsInstanceFilesRepliesUnderItsOwnIdForThePost(): void
    {
        $context = self::contextOf([self::statusOf('300', '9', []), self::statusOf('301', '110', [])]);
        $reader = new MastodonContextReplies(new EntrySanitizer(new TrailingBlankRemover()));

        $replies = $reader->of(self::POST, MastodonContextReplies::answerOf('9', $context));

        self::assertSame(['https://example.social/@someone/300'], self::urlsOf($replies));
    }

    public function testAContentWarningFoldsTheText(): void
    {
        self::assertStringStartsWith(
            '<details><summary>CW: spoilers</summary><p>The ending surprised me</p>',
            $this->replies()[0]->comment()->html,
        );
    }

    public function testAContentWarningIsEscaped(): void
    {
        $answer = self::contextOf([self::statusOf('300', '110', ['spoiler_text' => '<b>x</b> & y'])]);

        self::assertStringStartsWith(
            '<details><summary>CW: &lt;b&gt;x&lt;/b&gt; &amp; y</summary>',
            $this->replies($answer)[0]->comment()->html,
        );
    }

    public function testAReplyWithoutAWarningShowsItsText(): void
    {
        self::assertSame('<p>Related</p>', $this->replies()[1]->comment()->html);
    }

    public function testAReplysMarkupIsSanitized(): void
    {
        $answer = self::contextOf([self::statusOf('300', '110', [
            'content' => '<p onclick="steal()">Hi</p><script>alert(1)</script>',
        ])]);

        $html = $this->replies($answer)[0]->comment()->html;

        self::assertSame('<p>Hi</p>', $html);
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
        self::assertSame($replies[0]->replies[0]->comment()->url, $replies[0]->replies[0]->url);
        self::assertNotNull($replies[0]->replies[0]->url);
        self::assertFalse($replies[0]->byEntryAuthor);
    }

    public function testTheOriginalPosterIsKnownByTheirAccountUriToo(): void
    {
        $answer = self::contextOf([self::statusOf('300', '110', [
            'account' => [
                'acct' => 'Gargron',
                'url' => 'https://elsewhere.example/@x',
                'uri' => 'https://mastodon.social/users/Gargron',
            ],
        ])]);

        self::assertTrue(
            $this->replies($answer, 'https://mastodon.social/users/Gargron/statuses/110')[0]->comment()->byEntryAuthor,
        );
    }

    public function testAPostAtAnotherAddressShapeMarksNoReplyAsTheOriginalPosters(): void
    {
        $replies = $this->replies(post: 'https://mastodon.social/notes/110');

        self::assertFalse($replies[0]->replies[0]->comment()->byEntryAuthor);
    }

    public function testALocalAuthorsHandleNamesTheirInstance(): void
    {
        self::assertSame('@Gargron@mastodon.social', $this->replies()[0]->replies[0]->comment()->author->handle);
    }

    public function testAReplyNamesItsAuthor(): void
    {
        self::assertEquals(
            new CommentAuthorModel(
                'Ann',
                '@ann@hachyderm.io',
                'https://hachyderm.io/@ann',
                'https://files.mastodon.social/accounts/avatars/ann-static.png',
            ),
            $this->replies()[0]->comment()->author,
        );
    }

    public function testAReplyKeepsItsTime(): void
    {
        self::assertEquals(
            new \DateTimeImmutable('2026-10-16T09:00:00Z'),
            $this->replies()[0]->comment()->publishedAt,
        );
    }

    public function testAReplyWithoutAWebPageLinksItsUri(): void
    {
        $answer = self::contextOf([self::statusOf('300', '110', ['url' => null])]);

        self::assertSame(
            'https://example.social/users/someone/statuses/300',
            $this->replies($answer)[0]->comment()->url,
        );
    }

    public function testAttachmentsCardAndQuoteBecomeEmbeds(): void
    {
        $replies = $this->replies();
        $embeds = $replies[0]->comment()->embeds;

        self::assertSame(VisualMediaKind::Image->value, $embeds->media[0]->kind);
        self::assertSame('A review of the ending', $embeds->linkCard?->title);
        self::assertSame('https://fosstodon.org/@dee/77', $replies[1]->comment()->embeds->quote?->url);
        self::assertNull($embeds->quote);
    }

    public function testAStatusRepeatingAnIdIsReadOnce(): void
    {
        $answer = self::contextOf([
            self::statusOf('300', '110', []),
            self::statusOf('300', '300', []),
        ]);

        $replies = $this->replies($answer);

        self::assertCount(1, $replies);
        self::assertSame([], $replies[0]->replies);
    }

    public function testAnAnswerWithoutDescendantsIsUnavailable(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->replies('{"error":"Record not found"}');
    }

    public function testAnAnswerNamingNoPostIsUnavailable(): void
    {
        $reader = new MastodonContextReplies(new EntrySanitizer(new TrailingBlankRemover()));

        $this->expectException(CommentsUnavailableException::class);

        $reader->of(self::POST, json_encode(['context' => json_decode(self::fixture())], \JSON_THROW_ON_ERROR));
    }

    public function testAContextThatIsNoJsonCannotRenameThePost(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->replies('{"descendants":[]},"postId":"999"');
    }

    /** @return list<ReplyNode> */
    private function replies(?string $answer = null, string $post = self::POST): array
    {
        $reader = new MastodonContextReplies(new EntrySanitizer(new TrailingBlankRemover()));

        return $reader->of($post, MastodonContextReplies::answerOf('110', $answer ?? self::fixture()));
    }

    private static function fixture(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/social/mastodon-context.json');
        Assert::assertIsString($contents);

        return $contents;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function statusOf(string $id, string $inReplyToId, array $overrides): array
    {
        return [
            'id' => $id,
            'in_reply_to_id' => $inReplyToId,
            'visibility' => 'public',
            'spoiler_text' => '',
            'uri' => 'https://example.social/users/someone/statuses/' . $id,
            'url' => 'https://example.social/@someone/' . $id,
            'content' => '<p>A reply</p>',
            'account' => ['acct' => 'someone', 'url' => 'https://example.social/@someone'],
            ...$overrides,
        ];
    }

    /** @param list<array<string, mixed>> $descendants */
    private static function contextOf(array $descendants): string
    {
        return json_encode(['ancestors' => [], 'descendants' => $descendants], \JSON_THROW_ON_ERROR);
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
