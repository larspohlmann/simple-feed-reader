<?php

declare(strict_types=1);

namespace App\Service\Comments\Pass;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\MarkdownHtml;

final readonly class LemmyThread
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        private ?string $entryAuthorUrl,
        private MarkdownHtml $markdown,
        private EntrySanitizer $sanitizer,
    ) {
    }

    /**
     * @param list<JsonNodeModel> $nodes the comments in the order the instance ranked them
     *
     * @return list<EntryCommentModel>
     */
    public function comments(array $nodes): array
    {
        $unique = self::threadable($nodes);
        $repliesByParent = [];
        foreach ($unique as $node) {
            $repliesByParent[self::parentOf($node)][] = $node;
        }
        $roots = array_filter(
            $unique,
            static fn (JsonNodeModel $node): bool => !isset($unique[self::parentOf($node)]),
        );

        return $this->flattened(array_values($roots), $repliesByParent, 0);
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return array<string, JsonNodeModel> the first comment of each id, none its own parent
     */
    private static function threadable(array $nodes): array
    {
        $unique = [];
        foreach ($nodes as $node) {
            $id = self::idOf($node);
            if ('' !== $id && !isset($unique[$id]) && self::parentOf($node) !== $id) {
                $unique[$id] = $node;
            }
        }

        return $unique;
    }

    /**
     * @param list<JsonNodeModel>                $nodes
     * @param array<string, list<JsonNodeModel>> $repliesByParent
     *
     * @return list<EntryCommentModel>
     */
    private function flattened(array $nodes, array $repliesByParent, int $depth): array
    {
        $comments = [];
        foreach ($nodes as $node) {
            $comment = $this->commentOrNull($node, $depth);
            if (null !== $comment) {
                $comments[] = $comment;
            }
            $replyDepth = null === $comment ? $depth : $depth + 1;
            $replies = $repliesByParent[self::idOf($node)] ?? [];
            array_push($comments, ...$this->flattened($replies, $repliesByParent, $replyDepth));
        }

        return $comments;
    }

    private function commentOrNull(JsonNodeModel $node, int $depth): ?EntryCommentModel
    {
        $comment = $node->node('comment');
        if ($comment->isTrue('removed') || $comment->isTrue('deleted')) {
            return null;
        }
        $html = $this->sanitizer->sanitize($this->markdown->of($comment->text('content') ?? ''));
        if (null === $html) {
            return null;
        }
        $creator = $node->node('creator');
        $profileUrl = $creator->string('actor_id');

        return new EntryCommentModel(
            self::author($creator, $profileUrl),
            $comment->string('ap_id'),
            DateParser::parse($comment->string('published')),
            $html,
            null !== $profileUrl && $profileUrl === $this->entryAuthorUrl,
        )->atDepth($depth);
    }

    private static function author(JsonNodeModel $creator, ?string $profileUrl): CommentAuthorModel
    {
        $account = $creator->string('name');
        $host = null === $profileUrl ? null : parse_url($profileUrl, \PHP_URL_HOST);
        $hasHandle = null !== $account && \is_string($host);

        return new CommentAuthorModel(
            $creator->string('display_name') ?? $account,
            $hasHandle ? '@' . $account . '@' . $host : null,
            $profileUrl,
            $creator->string('avatar'),
        );
    }

    private static function idOf(JsonNodeModel $node): string
    {
        return (string) $node->node('comment')->int('id');
    }

    private static function parentOf(JsonNodeModel $node): string
    {
        $segments = explode('.', $node->node('comment')->string('path') ?? '');

        return $segments[\count($segments) - 2] ?? '0';
    }
}
