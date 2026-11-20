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
        $topLevel = array_filter(
            $unique,
            static fn (JsonNodeModel $node): bool => !isset($unique[self::extractParentId($node)]),
        );
        $roots = $this->arranged($topLevel, $repliesByParent, self::mostInterestingFirst(...));

        return $this->flattened($roots, $repliesByParent, 0);
    }

    /**
     * @param array<JsonNodeModel>                                                      $nodes
     * @param array<string, list<JsonNodeModel>>                                       $repliesByParent
     * @param \Closure(array{JsonNodeModel, EntryCommentModel}, array{JsonNodeModel, EntryCommentModel}): int $order
     *
     * @return list<array{JsonNodeModel, EntryCommentModel}>
     */
    private function arranged(array $nodes, array $repliesByParent, \Closure $order): array
    {
        $shown = $this->shown($nodes, $repliesByParent);
        usort($shown, $order);

        return $shown;
    }

    /**
     * @param array<JsonNodeModel>               $nodes
     * @param array<string, list<JsonNodeModel>> $repliesByParent
     *
     * @return list<array{JsonNodeModel, EntryCommentModel}> each hidden comment replaced by the replies it leaves
     */
    private function shown(array $nodes, array $repliesByParent): array
    {
        $shown = [];
        foreach ($nodes as $node) {
            $comment = $this->parseComment($node);
            array_push($shown, ...(null === $comment
                ? $this->shown($repliesByParent[self::readId($node)] ?? [], $repliesByParent)
                : [[$node, $comment]]));
        }

        return $shown;
    }

    /**
     * @param array{JsonNodeModel, EntryCommentModel} $left
     * @param array{JsonNodeModel, EntryCommentModel} $right
     */
    private static function mostInterestingFirst(array $left, array $right): int
    {
        return [self::computeInterest($right[0]), self::sortableTime($right[1])]
            <=> [self::computeInterest($left[0]), self::sortableTime($left[1])];
    }

    /**
     * @param array{JsonNodeModel, EntryCommentModel} $left
     * @param array{JsonNodeModel, EntryCommentModel} $right
     */
    private static function oldestFirst(array $left, array $right): int
    {
        return self::sortableTime($left[1]) <=> self::sortableTime($right[1]);
    }

    private static function computeInterest(JsonNodeModel $node): int
    {
        $counts = $node->node('counts');

        return ($counts->int('score') ?? 0) + 3 * ($counts->int('child_count') ?? 0);
    }

    private static function sortableTime(EntryCommentModel $comment): int
    {
        return $comment->publishedAt?->getTimestamp() ?? PHP_INT_MIN;
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
     * @param list<array{JsonNodeModel, EntryCommentModel}> $shown
     * @param array<string, list<JsonNodeModel>>            $repliesByParent
     *
     * @return list<EntryCommentModel>
     */
    private function flattened(array $shown, array $repliesByParent, int $depth): array
    {
        $comments = [];
        foreach ($shown as [$node, $comment]) {
            $comments[] = $comment->atDepth($depth);
            $replies = $repliesByParent[self::readId($node)] ?? [];
            $arranged = $this->arranged($replies, $repliesByParent, self::oldestFirst(...));
            array_push($comments, ...$this->flattened($arranged, $repliesByParent, $depth + 1));
        }

        return $comments;
    }

    private function parseComment(JsonNodeModel $node): ?EntryCommentModel
    {
        $comment = $node->node('comment');
        if ($comment->isTrue('removed') || $comment->isTrue('deleted')) {
            return null;
        }
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
        );

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
