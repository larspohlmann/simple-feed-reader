<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;
use App\Service\Comments\Model\EntryCommentModel;

/** Marks the replies one of the viewer's own accounts wrote, so the panel can offer to delete them. */
final class OwnReplies
{
    private function __construct()
    {
    }

    /**
     * @param list<EntryCommentModel> $comments
     * @param list<SocialAccount>     $ownAccounts
     *
     * @return list<EntryCommentModel>
     */
    public static function marked(array $comments, array $ownAccounts): array
    {
        return array_map(
            static fn (EntryCommentModel $comment): EntryCommentModel => self::isOwn($comment, $ownAccounts)
                && null !== $comment->post
                ? $comment->withPost($comment->post->ownedByViewer())
                : $comment,
            $comments,
        );
    }

    /** @param list<SocialAccount> $ownAccounts */
    private static function isOwn(EntryCommentModel $comment, array $ownAccounts): bool
    {
        return null !== SocialIdentity::accountAmong($comment->post?->authorIdentity, $ownAccounts);
    }
}
