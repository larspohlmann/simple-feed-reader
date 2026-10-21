<?php

declare(strict_types=1);

namespace App\Service\Comments\Pass;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Model\SubstackPostModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Text\Support\LinkedPlainText;

final readonly class SubstackThread
{
    private const string PROFILE = 'https://substack.com/@';

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private SubstackPostModel $post, private ?string $entryAuthor)
    {
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<EntryCommentModel>
     */
    public function comments(array $nodes, int $depth = 0): array
    {
        $comments = [];
        foreach ($nodes as $node) {
            $comment = $this->commentOrNull($node, $depth);
            if (null !== $comment) {
                $comments[] = $comment;
            }
            $replyDepth = null === $comment ? $depth : $depth + 1;
            array_push($comments, ...$this->comments($node->nodes('children'), $replyDepth));
        }

        return $comments;
    }

    private function commentOrNull(JsonNodeModel $node, int $depth): ?EntryCommentModel
    {
        $id = $node->int('id');
        $html = LinkedPlainText::asHtml($node->text('body') ?? '');
        if (null === $id || null === $html || $node->isTrue('deleted')) {
            return null;
        }
        $name = $node->string('name');

        return (new EntryCommentModel(
            self::author($node, $name),
            $this->post->commentUrl($id),
            DateParser::parse($node->string('date')),
            $html,
            null !== $name && $name === $this->entryAuthor,
        ))->atDepth($depth);
    }

    private static function author(JsonNodeModel $node, ?string $name): CommentAuthorModel
    {
        $handle = $node->string('handle');

        return new CommentAuthorModel(
            $name,
            null === $handle ? null : '@' . $handle,
            null === $handle ? null : self::PROFILE . $handle,
            $node->string('photo_url'),
        );
    }
}
