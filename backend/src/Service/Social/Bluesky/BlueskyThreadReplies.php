<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentAuthorModel;
use App\Service\Content\Comments\Model\CommentEmbedsModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Text\Support\HtmlAnchor;
use App\Service\Ingestion\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingestion\Parser\Support\DateParser;
use App\Service\Social\Bluesky\Support\BlueskyAuthor;
use App\Service\Social\Bluesky\Support\BlueskyCommentPost;
use App\Service\Social\Bluesky\Support\BlueskyPostCounts;
use App\Service\Social\BlueskyPost\Model\RenderedEmbedModel;
use App\Service\Social\BlueskyPost\PostEmbedRenderer;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\BlueskyPost\Support\BlueskyRichText;
use App\Service\Social\Social\Pass\ReplyNode;

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
    public function parseReplies(string $postAddress, string $answer): array
    {
        $thread = JsonNodeModel::fromValue(json_decode($answer, true))->node('thread');
        if ($thread->type() !== self::THREAD_VIEW) {
            throw new CommentsUnavailableException('Bluesky answered without the post\'s thread.');
        }

        return $this->buildNodes($thread->nodes('replies'), AtPostUri::parseAuthority($postAddress));
    }

    /**
     * @param list<JsonNodeModel> $replies
     *
     * @return list<ReplyNode>
     */
    private function buildNodes(array $replies, ?string $entryAuthor): array
    {
        $nodes = [];
        foreach ($replies as $reply) {
            if (self::isShown($reply)) {
                $post = $reply->node('post');
                $nodes[] = new ReplyNode(
                    self::parsePublishedAt($post),
                    fn (?\DateTimeImmutable $publishedAt): EntryCommentModel
                        => $this->buildComment($post, $publishedAt, $entryAuthor),
                    $this->buildNodes($reply->nodes('replies'), $entryAuthor),
                    url: AtPostUri::webUrl($post->string('uri') ?? ''),
                    byEntryAuthor: self::isByEntryAuthor($post->node('author'), $entryAuthor),
                    counts: BlueskyPostCounts::parsePost($post),
                );
            }
        }

        return $nodes;
    }

    public function buildSelfReply(JsonNodeModel $post): EntryCommentModel
    {
        return $this->buildComment($post, self::parsePublishedAt($post), $post->node('author')->string('did'));
    }

    private static function isShown(JsonNodeModel $reply): bool
    {
        $viewer = $reply->node('post')->node('author')->node('viewer');

        return $reply->type() === self::THREAD_VIEW
            && !$viewer->isTrue('muted')
            && !$viewer->isTrue('blockedBy')
            && !$viewer->has('blocking');
    }

    private function buildComment(
        JsonNodeModel $post,
        ?\DateTimeImmutable $publishedAt,
        ?string $entryAuthor,
    ): EntryCommentModel {
        $author = $post->node('author');
        $rendered = $this->embeds->render($post);

        return new EntryCommentModel(
            self::buildAuthor($author),
            AtPostUri::webUrl($post->string('uri') ?? ''),
            $publishedAt,
            $this->buildHtml($post->node('record'), $rendered?->uncardedLink),
            self::isByEntryAuthor($author, $entryAuthor),
            self::buildEmbeds($rendered),
            post: BlueskyCommentPost::parsePost($post),
        );
    }

    private static function isByEntryAuthor(JsonNodeModel $author, ?string $entryAuthor): bool
    {
        return $entryAuthor !== null
            && \in_array($entryAuthor, [$author->string('did'), $author->string('handle')], true);
    }

    private static function parsePublishedAt(JsonNodeModel $post): ?\DateTimeImmutable
    {
        return DateParser::parse($post->node('record')->string('createdAt') ?? $post->string('indexedAt'));
    }

    private static function buildAuthor(JsonNodeModel $author): CommentAuthorModel
    {
        $handle = $author->string('handle');

        return new CommentAuthorModel(
            BlueskyAuthor::name($author),
            $handle === null ? null : '@' . $handle,
            BlueskyAuthor::profile($author),
            BlueskyAuthor::avatar($author),
        );
    }

    private function buildHtml(JsonNodeModel $record, ?string $uncardedLink): string
    {
        $text = BlueskyRichText::asHtml($record->text('text') ?? '', $record->nodes('facets')) ?? '';
        $link = $uncardedLink === null ? '' : sprintf('<p>%s</p>', HtmlAnchor::to($uncardedLink, $uncardedLink));

        return $this->sanitizer->sanitize($text . $link) ?? '';
    }

    private static function buildEmbeds(?RenderedEmbedModel $rendered): CommentEmbedsModel
    {
        if ($rendered === null) {
            return new CommentEmbedsModel();
        }

        return new CommentEmbedsModel(
            EntryMediaAssembler::assembleVisualMedia($rendered->media),
            $rendered->linkCards[0] ?? null,
            $rendered->quote,
        );
    }
}
