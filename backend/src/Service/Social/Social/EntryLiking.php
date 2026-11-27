<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\SocialAccountRepository;
use App\Service\Library\Reading\LocalLikes;

/** The thumb on any entry: a like on the post's network where the reader holds an account there, else a local one. */
final readonly class EntryLiking
{
    public function __construct(
        private SocialLikes $socialLikes,
        private LocalLikes $localLikes,
        private SocialPostAddresses $postAddresses,
        private SocialAccountRepository $socialAccounts,
    ) {
    }

    /** @return int|null the post's like count on its network when known */
    public function like(User $user, EntryListRow $row): ?int
    {
        if ($this->allowsNetworkLike($user, $row->entry)) {
            return $this->socialLikes->like($user, $row->entry);
        }
        $this->localLikes->like($user, $row);

        return null;
    }

    /** A network unlike takes any local like back too, so no like outlives the thumb that shows it. */
    public function unlike(User $user, EntryListRow $row): ?int
    {
        $likeCount = $this->allowsNetworkLike($user, $row->entry)
            ? $this->socialLikes->unlike($user, $row->entry)
            : null;
        $this->localLikes->unlike($user, $row);

        return $likeCount;
    }

    /** The rule ConnectedAccountStates shows as `canLike`: a post, and an account in any status on its network. */
    private function allowsNetworkLike(User $user, Entry $entry): bool
    {
        $post = $this->postAddresses->findPost($entry);

        return null !== $post && null !== $this->socialAccounts->findActingOnNetwork($user, $post->network);
    }
}
