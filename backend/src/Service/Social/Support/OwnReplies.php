<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Comments\Model\EntryCommentModel;

/** Marks the replies one of the viewer's own accounts wrote, so the panel can offer to delete them. */
final class OwnReplies
{
    private function __construct()
    {
    }

    /**
     * @param list<EntryCommentModel> $comments
     * @param list<string>            $ownIdentities the viewer's account identities, in lower case
     *
     * @return list<EntryCommentModel>
     */
    public static function marked(array $comments, array $ownIdentities): array
    {
        return array_map(
            static fn (EntryCommentModel $comment): EntryCommentModel => self::isOwn($comment, $ownIdentities)
                && null !== $comment->post
                ? $comment->withPost($comment->post->ownedByViewer())
                : $comment,
            $comments,
        );
    }

    /** @param list<string> $ownIdentities */
    private static function isOwn(EntryCommentModel $comment, array $ownIdentities): bool
    {
        $author = $comment->post?->authorIdentity;

        return null !== $author && \in_array(mb_strtolower($author), $ownIdentities, true);
    }
}
