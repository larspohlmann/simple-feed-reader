<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\Support\OwnPostIds;
use App\Service\Social\Support\PostAuthor;
use App\Service\Social\Support\SocialPostAddress;

/** Deletes one of the user's own posts on its network, its folded replies newest first, then every copy here. */
final readonly class OwnPostDeletion
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private ThreadPostRepository $threadPosts,
        private PostWriting $writing,
        private LocalPostRemoval $localRemoval,
    ) {
    }

    /**
     * Every address is named before the first delete, so a thread is never left half-deleted for want of one.
     *
     * @throws NotASocialPostException
     * @throws SocialPostRefusedException when no account of the user's wrote the post, or a post of it has no address
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function delete(User $user, Entry $entry): PostDeletion
    {
        $post = SocialPostAddress::ofOrRefuse($entry);
        $author = $this->authorOf($user, $entry, $post->network);
        $postId = OwnPostIds::ofAddress($post, $author);
        foreach ($this->replyIdsOf($entry, $author) as $replyId) {
            $this->writing->delete($author, $replyId);
        }
        $deletion = $this->writing->delete($author, $postId);
        $this->localRemoval->remove($entry);

        return $deletion;
    }

    private function authorOf(User $user, Entry $entry, SocialNetwork $network): SocialAccount
    {
        return PostAuthor::accountAmong($entry, $this->socialAccounts->findAllOnNetwork($user, $network))
            ?? throw new SocialPostRefusedException('No connected account wrote this post.');
    }

    /** @return list<string> */
    private function replyIdsOf(Entry $entry, SocialAccount $author): array
    {
        return array_map(
            static fn (string $url): string => OwnPostIds::ofReplyUrl($url, $author),
            $this->threadPosts->urlsNewestFirst($entry),
        );
    }
}
