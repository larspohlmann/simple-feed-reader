<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Social\Pass\MastodonReplyThread;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\Support\MastodonAttachments;
use App\Service\Social\Support\MastodonAuthor;
use App\Service\Social\Support\MastodonCard;
use App\Service\Social\Support\MastodonQuotedStatus;
use App\Service\Social\Support\MastodonStatusAddress;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class MastodonContextReplies
{
    public function __construct(private EntrySanitizer $sanitizer)
    {
    }

    public static function answerOf(string $postId, string $context): string
    {
        return sprintf(
            '{"postId":%s,"context":%s}',
            json_encode($postId, \JSON_THROW_ON_ERROR),
            json_validate($context) ? $context : 'null',
        );
    }

    /**
     * @return list<ReplyNode>
     *
     * @throws CommentsUnavailableException when the answer holds no post id or no descendants list
     */
    public function of(string $postAddress, string $answer): array
    {
        $envelope = JsonNodeModel::of(json_decode($answer, true));
        $postId = $envelope->string('postId')
            ?? throw new CommentsUnavailableException('The Mastodon answer names no post.');
        $descendants = $envelope->node('context')->listAt('descendants')
            ?? throw new CommentsUnavailableException('The Mastodon instance answered without the replies.');
        $thread = new MastodonReplyThread(
            $postId,
            MastodonStatusAddress::authorPrefixOrNull($postAddress),
            $descendants,
        );

        return $this->nodesOf($thread->topLevel(), $thread);
    }

    /**
     * @param list<JsonNodeModel> $statuses
     *
     * @return list<ReplyNode>
     */
    private function nodesOf(array $statuses, MastodonReplyThread $thread): array
    {
        return array_map(
            fn (JsonNodeModel $status): ReplyNode => new ReplyNode(
                self::publishedAtOf($status),
                fn (?\DateTimeImmutable $publishedAt): EntryCommentModel
                    => $this->commentOf($status, $publishedAt, $thread),
                $this->nodesOf($thread->answersTo($status), $thread),
            ),
            $statuses,
        );
    }

    private function commentOf(
        JsonNodeModel $status,
        ?\DateTimeImmutable $publishedAt,
        MastodonReplyThread $thread,
    ): EntryCommentModel {
        $account = $status->node('account');

        return new EntryCommentModel(
            self::authorOf($account),
            AbsoluteHttpUrl::orNull($status->string('url')) ?? AbsoluteHttpUrl::orNull($status->string('uri')),
            $publishedAt,
            $this->htmlOf($status),
            $thread->isByPostAuthor($account),
            self::embedsOf($status),
        );
    }

    private static function publishedAtOf(JsonNodeModel $status): ?\DateTimeImmutable
    {
        return DateParser::parse($status->string('created_at'));
    }

    private static function authorOf(JsonNodeModel $account): CommentAuthorModel
    {
        $handle = MastodonAuthor::handle($account);

        return new CommentAuthorModel(
            MastodonAuthor::name($account),
            $handle === null ? null : '@' . $handle,
            MastodonAuthor::profile($account),
            MastodonAuthor::avatar($account),
        );
    }

    private function htmlOf(JsonNodeModel $status): string
    {
        $content = $status->string('content') ?? '';
        $warning = $status->string('spoiler_text');
        $folded = $warning === null
            ? $content
            : sprintf('<details><summary>CW: %s</summary>%s</details>', HtmlEscape::text($warning), $content);

        return $this->sanitizer->sanitize($folded) ?? '';
    }

    private static function embedsOf(JsonNodeModel $status): CommentEmbedsModel
    {
        return new CommentEmbedsModel(
            MastodonAttachments::entryMediaOf($status),
            MastodonCard::of($status->node('card')),
            MastodonQuotedStatus::ofQuote($status->node('quote')),
        );
    }
}
