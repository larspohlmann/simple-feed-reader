<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Bluesky\Support\BlueskyRichText;
use App\Service\Bluesky\Support\BlueskyWeb;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentEmbedsModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Parser\Support\DateParser;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Text\Support\HtmlAnchor;
use App\Service\Url\Support\AbsoluteHttpUrl;

final readonly class BlueskyThreadReplies
{
    private const string THREAD_VIEW = 'app.bsky.feed.defs#threadViewPost';

    public function __construct(private PostEmbedRenderer $embeds, private EntrySanitizer $sanitizer)
    {
    }

    /**
     * @return list<ReplyNode>
     *
     * @throws CommentsUnavailableException when the answer holds no thread
     */
    public function of(string $postAddress, string $answer): array
    {
        $thread = JsonNodeModel::of(json_decode($answer, true))->node('thread');
        if ($thread->type() !== self::THREAD_VIEW) {
            throw new CommentsUnavailableException('Bluesky answered without the post\'s thread.');
        }

        return $this->nodesOf($thread->nodes('replies'), AtPostUri::authorityOf($postAddress));
    }

    /**
     * @param list<JsonNodeModel> $replies
     *
     * @return list<ReplyNode>
     */
    private function nodesOf(array $replies, ?string $entryAuthor): array
    {
        $nodes = [];
        foreach ($replies as $reply) {
            if (self::isShown($reply)) {
                $post = $reply->node('post');
                $nodes[] = new ReplyNode(
                    self::publishedAtOf($post),
                    fn (?\DateTimeImmutable $publishedAt): EntryCommentModel
                        => $this->commentOf($post, $publishedAt, $entryAuthor),
                    $this->nodesOf($reply->nodes('replies'), $entryAuthor),
                );
            }
        }

        return $nodes;
    }

    private static function isShown(JsonNodeModel $reply): bool
    {
        $viewer = $reply->node('post')->node('author')->node('viewer');

        return $reply->type() === self::THREAD_VIEW
            && !$viewer->isTrue('muted')
            && !$viewer->isTrue('blockedBy')
            && !$viewer->has('blocking');
    }

    private function commentOf(
        JsonNodeModel $post,
        ?\DateTimeImmutable $publishedAt,
        ?string $entryAuthor,
    ): EntryCommentModel {
        $author = $post->node('author');
        $rendered = $this->embeds->render($post);

        return new EntryCommentModel(
            self::authorOf($author),
            AtPostUri::webUrl($post->string('uri') ?? ''),
            $publishedAt,
            $this->htmlOf($post->node('record'), $rendered?->uncardedLink),
            $entryAuthor !== null && \in_array($entryAuthor, [$author->string('did'), $author->string('handle')], true),
            self::embedsOf($rendered),
        );
    }

    private static function publishedAtOf(JsonNodeModel $post): ?\DateTimeImmutable
    {
        return DateParser::parse($post->node('record')->string('createdAt') ?? $post->string('indexedAt'));
    }

    private static function authorOf(JsonNodeModel $author): CommentAuthorModel
    {
        $handle = $author->string('handle');
        $actor = $handle ?? $author->string('did');

        return new CommentAuthorModel(
            $author->string('displayName') ?? $handle,
            $handle === null ? null : '@' . $handle,
            $actor === null ? null : BlueskyWeb::profileUrl($actor),
            AbsoluteHttpUrl::orNull($author->string('avatar')),
        );
    }

    private function htmlOf(JsonNodeModel $record, ?string $uncardedLink): string
    {
        $text = BlueskyRichText::asHtml($record->text('text') ?? '', $record->nodes('facets')) ?? '';
        $link = $uncardedLink === null ? '' : sprintf('<p>%s</p>', HtmlAnchor::to($uncardedLink, $uncardedLink));

        return $this->sanitizer->sanitize($text . $link) ?? '';
    }

    private static function embedsOf(?RenderedEmbedModel $rendered): CommentEmbedsModel
    {
        if ($rendered === null) {
            return new CommentEmbedsModel();
        }

        return new CommentEmbedsModel(
            EntryMediaAssembler::mediaOf($rendered->media),
            $rendered->linkCards[0] ?? null,
            $rendered->quote,
        );
    }
}
