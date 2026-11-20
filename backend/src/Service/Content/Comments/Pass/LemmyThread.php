<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Pass;

use App\Enum\VoteScore;
use App\Service\Content\Comments\Model\CommentAuthorModel;
use App\Service\Content\Comments\Model\CommentVoteModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Content\Comments\Support\LemmyAuthor;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Ingestion\Parser\Support\DateParser;

final readonly class LemmyThread
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        private ?string $entryAuthorUrl,
        private MarkdownHtml $markdown,
        private EntrySanitizer $sanitizer,
        private bool $carriesVotes,
    ) {
    }

    /**
     * @param list<JsonNodeModel> $nodes the comments in any order
     *
     * @return list<EntryCommentModel>
     */
    public function comments(array $nodes): array
    {
        $unique = self::threadable($nodes);
        $repliesByParent = [];
        foreach ($unique as $node) {
            $repliesByParent[self::extractParentId($node)][] = $node;
        }
        $roots = array_filter(
            $unique,
            static fn (JsonNodeModel $node): bool => !isset($unique[self::extractParentId($node)]),
        );
        $roots = self::shown(array_values($roots), $repliesByParent);
        usort($roots, self::mostInterestingFirst(...));

        return $this->flattened($roots, $repliesByParent, 0);
    }

    /**
     * @param list<JsonNodeModel>                $nodes
     * @param array<string, list<JsonNodeModel>> $repliesByParent
     *
     * @return list<JsonNodeModel> the nodes, each removed one replaced by the replies it leaves behind
     */
    private static function shown(array $nodes, array $repliesByParent): array
    {
        $shown = [];
        foreach ($nodes as $node) {
            if (!self::isGone($node)) {
                $shown[] = $node;
                continue;
            }
            array_push($shown, ...self::shown($repliesByParent[self::readId($node)] ?? [], $repliesByParent));
        }

        return $shown;
    }

    private static function mostInterestingFirst(JsonNodeModel $left, JsonNodeModel $right): int
    {
        return [self::computeInterest($right), self::sortableTime($right)]
            <=> [self::computeInterest($left), self::sortableTime($left)];
    }

    private static function oldestFirst(JsonNodeModel $left, JsonNodeModel $right): int
    {
        return self::sortableTime($left) <=> self::sortableTime($right);
    }

    private static function computeInterest(JsonNodeModel $node): int
    {
        $counts = $node->node('counts');

        return ($counts->int('score') ?? 0) + 3 * ($counts->int('child_count') ?? 0);
    }

    private static function sortableTime(JsonNodeModel $node): int
    {
        return DateParser::parse($node->node('comment')->string('published'))?->getTimestamp() ?? PHP_INT_MIN;
    }

    private static function isGone(JsonNodeModel $node): bool
    {
        $comment = $node->node('comment');

        return $comment->isTrue('removed') || $comment->isTrue('deleted');
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
            $id = self::readId($node);
            if ('' !== $id && !isset($unique[$id]) && self::extractParentId($node) !== $id) {
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
            $comment = $this->parseComment($node, $depth);
            if (null !== $comment) {
                $comments[] = $comment;
            }
            $replyDepth = null === $comment ? $depth : $depth + 1;
            $replies = self::shown($repliesByParent[self::readId($node)] ?? [], $repliesByParent);
            usort($replies, self::oldestFirst(...));
            array_push($comments, ...$this->flattened($replies, $repliesByParent, $replyDepth));
        }

        return $comments;
    }

    private function parseComment(JsonNodeModel $node, int $depth): ?EntryCommentModel
    {
        $comment = $node->node('comment');
        $html = $this->sanitizer->sanitize($this->markdown->toHtml($comment->text('content') ?? ''));
        if (null === $html) {
            return null;
        }
        $creator = $node->node('creator');
        $profileUrl = $creator->string('actor_id');

        $address = $comment->string('ap_id');
        $entryComment = new EntryCommentModel(
            self::author($creator, $profileUrl),
            $address,
            DateParser::parse($comment->string('published')),
            $html,
            null !== $profileUrl && $profileUrl === $this->entryAuthorUrl,
            score: $node->node('counts')->int('score'),
        )->atDepth($depth);

        return $this->carriesVotes && null !== $address
            ? $entryComment->withVote(self::vote($node, $address))
            : $entryComment;
    }

    private static function vote(JsonNodeModel $node, string $address): CommentVoteModel
    {
        return new CommentVoteModel(
            $address,
            VoteScore::fromNetworkValue($node->int('my_vote')),
            !$node->node('post')->isTrue('locked'),
        );
    }

    private static function author(JsonNodeModel $creator, ?string $profileUrl): CommentAuthorModel
    {
        return new CommentAuthorModel(
            $creator->string('display_name') ?? $creator->string('name'),
            LemmyAuthor::handle($creator->string('name'), $profileUrl),
            $profileUrl,
            $creator->string('avatar'),
        );
    }

    private static function readId(JsonNodeModel $node): string
    {
        return (string) $node->node('comment')->int('id');
    }

    private static function extractParentId(JsonNodeModel $node): string
    {
        $segments = explode('.', $node->node('comment')->string('path') ?? '');

        return $segments[\count($segments) - 2] ?? '0';
    }
}
