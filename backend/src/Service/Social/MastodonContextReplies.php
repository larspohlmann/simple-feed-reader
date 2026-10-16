<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Parser\Support\DateParser;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Social\Model\ReplyNodeModel;
use App\Service\Social\Support\MastodonAttachments;
use App\Service\Social\Support\MastodonAuthor;
use App\Service\Social\Support\MastodonCard;
use App\Service\Social\Support\MastodonQuotedStatus;
use App\Service\Social\Support\MastodonStatusAddress;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class MastodonContextReplies
{
    private const array SHOWN_VISIBILITIES = ['public', 'unlisted'];
    private const string THE_POST = '';

    public function __construct(private EntrySanitizer $sanitizer)
    {
    }

    /**
     * @return list<ReplyNodeModel>
     *
     * @throws CommentsUnavailableException when the answer holds no descendants list
     */
    public function of(string $postAddress, string $answer): array
    {
        $descendants = JsonNodeModel::of(json_decode($answer, true))->listAt('descendants')
            ?? throw new CommentsUnavailableException('The Mastodon instance answered without the replies.');
        $entryAuthor = MastodonStatusAddress::authorPrefixOrNull($postAddress);

        return self::nodesOf($this->repliesByParent($descendants, $entryAuthor), self::THE_POST);
    }

    /**
     * A reply answering a status outside the list answers the post itself, by whatever id the post has there.
     *
     * @param list<JsonNodeModel> $descendants
     *
     * @return array<array-key, list<array{string, EntryCommentModel}>>
     */
    private function repliesByParent(array $descendants, ?string $entryAuthor): array
    {
        $listed = array_flip(array_filter(array_map(
            static fn (JsonNodeModel $status): ?string => $status->string('id'),
            $descendants,
        )));
        $byParent = [];
        $read = [];
        foreach ($descendants as $status) {
            $id = $status->string('id');
            if ($id === null || isset($read[$id]) || !self::isShown($status)) {
                continue;
            }
            $read[$id] = true;
            $parent = $status->string('in_reply_to_id');
            $byParent[$parent !== null && isset($listed[$parent]) ? $parent : self::THE_POST][]
                = [$id, $this->commentOf($status, $entryAuthor)];
        }

        return $byParent;
    }

    /**
     * @param array<array-key, list<array{string, EntryCommentModel}>> $repliesByParent
     *
     * @return list<ReplyNodeModel>
     */
    private static function nodesOf(array $repliesByParent, string $parent): array
    {
        return array_map(
            static fn (array $reply): ReplyNodeModel => new ReplyNodeModel(
                $reply[1],
                self::nodesOf($repliesByParent, $reply[0]),
            ),
            $repliesByParent[$parent] ?? [],
        );
    }

    private static function isShown(JsonNodeModel $status): bool
    {
        return \in_array($status->string('visibility'), self::SHOWN_VISIBILITIES, true);
    }

    private function commentOf(JsonNodeModel $status, ?string $entryAuthor): EntryCommentModel
    {
        $account = $status->node('account');

        return new EntryCommentModel(
            self::authorOf($account),
            AbsoluteHttpUrl::orNull($status->string('url')) ?? AbsoluteHttpUrl::orNull($status->string('uri')),
            DateParser::parse($status->string('created_at')),
            $this->htmlOf($status),
            $entryAuthor !== null
                && \in_array($entryAuthor, [$account->string('url'), $account->string('uri')], true),
            self::embedsOf($status),
        );
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
        $attachments = MastodonAttachments::of($status->nodes('media_attachments'));

        return new CommentEmbedsModel(
            EntryMediaAssembler::assemble(null, $attachments, [])->media,
            MastodonCard::of($status->node('card')),
            MastodonQuotedStatus::ofQuote($status->node('quote')),
        );
    }
}
