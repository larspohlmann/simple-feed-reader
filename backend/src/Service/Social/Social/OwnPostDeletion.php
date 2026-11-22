<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\Model\PostDeletion;

/** Deletes one of the user's own posts on its network, its folded replies newest first, then every copy here. */
final readonly class OwnPostDeletion
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private ThreadPostRepository $threadPosts,
        private PostDeletions $deletions,
        private LocalPostRemoval $localRemoval,
        private SocialPostAddresses $postAddresses,
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
        $post = $this->postAddresses->requirePost($entry);
        $author = $this->requireAuthor($user, $entry, $post->network);
        $replyUrls = $this->requireReplyUrls($entry);
        $postIds = [
            ...$this->requireReplyIds($replyUrls, $author),
            $this->postAddresses->selectRule($post->network)->requireOwnPostId($post, $author),
        ];
        $replyGuids = $this->requireReplyGuids($replyUrls, $author);
        foreach ($postIds as $postId) {
            $deletion = $this->deletions->delete($author, $postId);
        }
        $this->localRemoval->remove($entry, $replyGuids);

        return $deletion;
    }

    private function requireAuthor(User $user, Entry $entry, SocialNetwork $network): SocialAccount
    {
        return $this->postAddresses->findAuthorAmong($entry, $this->socialAccounts->findAllOnNetwork($user, $network))
            ?? throw new SocialPostRefusedException('No connected account wrote this post.');
    }

    /** @return list<string> */
    private function requireReplyUrls(Entry $entry): array
    {
        return array_map(
            static fn (?string $url): string => $url
                ?? throw new SocialPostRefusedException('A reply in the thread has no address.'),
            $this->threadPosts->urlsNewestFirst($entry),
        );
    }

    /**
     * @param list<string> $replyUrls
     *
     * @return list<string>
     */
    private function requireReplyIds(array $replyUrls, SocialAccount $author): array
    {
        $rule = $this->postAddresses->selectRule($author->getNetwork());

        return array_map(static fn (string $url): string => $rule->requireOwnReplyId($url, $author), $replyUrls);
    }

    /**
     * @param list<string> $replyUrls
     *
     * @return list<string>
     */
    private function requireReplyGuids(array $replyUrls, SocialAccount $author): array
    {
        $rule = $this->postAddresses->selectRule($author->getNetwork());

        return array_merge(...array_map(
            static fn (string $url): array => $rule->requireOwnReplyGuids($url, $author),
            $replyUrls,
        ));
    }
}
