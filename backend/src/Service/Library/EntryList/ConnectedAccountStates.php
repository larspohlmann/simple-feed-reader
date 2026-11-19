<?php

declare(strict_types=1);

namespace App\Service\Library\EntryList;

use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use App\Repository\DiscussionVoteRepository;
use App\Repository\EntryLemmyPostRepository;
use App\Repository\EntryListRowLemmy;
use App\Repository\EntryListRowSocial;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialLikeRepository;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Library\EntryList\Model\ConnectedAccountStatesModel;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\SocialPostAddresses;
use App\Service\Social\Social\Support\ProfileFeedUrl;

/** What a page of entries looks like to the reader's connected accounts: likes, own posts and Lemmy votes. */
final readonly class ConnectedAccountStates
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialLikeRepository $socialLikes,
        private DiscussionVoteRepository $discussionVotes,
        private EntryLemmyPostRepository $lemmyPosts,
        private SocialPostAddresses $postAddresses,
    ) {
    }

    /** @param array<int, Entry> $entries by entry id */
    public function buildStates(array $entries, int $userId): ConnectedAccountStatesModel
    {
        $postAddresses = $this->postAddressesOf($entries);
        $lemmyPostIds = self::lemmyPostIdsOf($entries);
        $accountsByNetwork = $this->accountsByNetworkOf($postAddresses, $lemmyPostIds, $userId);
        $socialStates = $this->socialStatesOf($entries, $postAddresses, $accountsByNetwork, $userId);
        $votes = $this->votesOf($lemmyPostIds, $accountsByNetwork, $userId);
        $lemmyPosts = $this->lemmyPosts->byEntryIds(array_keys($entries));

        return new ConnectedAccountStatesModel($socialStates, self::lemmyStatesOf($entries, $lemmyPosts, $votes));
    }

    /**
     * @param array<int, Entry> $entries
     * @param array<int, EntryLemmyPost> $lemmyPosts
     * @param array<int, VoteScore> $votes
     *
     * @return array<int, EntryListRowLemmy>
     */
    private static function lemmyStatesOf(array $entries, array $lemmyPosts, array $votes): array
    {
        $states = [];
        foreach ($entries as $entryId => $entry) {
            $lemmy = self::lemmyOf($entry, $lemmyPosts[$entryId] ?? null, $votes[$entryId] ?? null);
            if (null !== $lemmy) {
                $states[$entryId] = $lemmy;
            }
        }

        return $states;
    }

    /**
     * @param array<int, SocialPostAddressModel> $postAddresses
     * @param list<int> $lemmyPostIds
     *
     * @return array<string, non-empty-list<SocialAccount>> by network value; read only when a post can use them
     */
    private function accountsByNetworkOf(array $postAddresses, array $lemmyPostIds, int $userId): array
    {
        if ([] === $postAddresses && [] === $lemmyPostIds) {
            return [];
        }

        return self::accountsByNetwork($this->socialAccounts->findAllForUserIdInActingOrder($userId));
    }

    /**
     * @param list<int> $lemmyPostIds
     * @param array<string, non-empty-list<SocialAccount>> $accountsByNetwork
     *
     * @return array<int, VoteScore> by entry id, for every Lemmy post of a reader with a Lemmy account in any status
     */
    private function votesOf(array $lemmyPostIds, array $accountsByNetwork, int $userId): array
    {
        if (!isset($accountsByNetwork[SocialNetwork::Lemmy->value])) {
            return [];
        }

        $stored = $this->discussionVotes->scoresAmong($userId, $lemmyPostIds);

        return $stored + array_fill_keys($lemmyPostIds, VoteScore::None);
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return list<int>
     */
    private static function lemmyPostIdsOf(array $entries): array
    {
        return array_keys(array_filter(
            $entries,
            static fn (Entry $entry): bool => null !== LemmyPostAddress::of($entry),
        ));
    }

    /**
     * @param array<int, Entry> $entries
     * @param array<int, SocialPostAddressModel> $posts
     * @param array<string, non-empty-list<SocialAccount>> $accountsByNetwork
     *
     * @return array<int, EntryListRowSocial> by entry id, for every post
     */
    private function socialStatesOf(array $entries, array $posts, array $accountsByNetwork, int $userId): array
    {
        if ([] === $posts) {
            return [];
        }
        $likeable = array_filter(
            $posts,
            static fn (SocialPostAddressModel $post): bool => isset($accountsByNetwork[$post->network->value]),
        );
        $likedIds = $this->socialLikes->likedEntryIdsAmong($userId, array_keys($likeable));

        $states = [];
        foreach ($posts as $entryId => $post) {
            $networkAccounts = $accountsByNetwork[$post->network->value] ?? [];
            $canLike = [] !== $networkAccounts;
            $states[$entryId] = new EntryListRowSocial(
                $post->network,
                isLiked: isset($likedIds[$entryId]),
                canLike: $canLike,
                isOwn: $canLike && null !== $this->postAddresses->findAuthorAmong($entries[$entryId], $networkAccounts),
            );
        }

        return $states;
    }

    /**
     * @param list<SocialAccount> $accounts
     *
     * @return array<string, non-empty-list<SocialAccount>> by network value
     */
    private static function accountsByNetwork(array $accounts): array
    {
        $byNetwork = [];
        foreach ($accounts as $account) {
            $byNetwork[$account->getNetwork()->value][] = $account;
        }

        return $byNetwork;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, SocialPostAddressModel> the address of each social post, decided without a query
     */
    private function postAddressesOf(array $entries): array
    {
        $addresses = [];
        foreach ($entries as $entryId => $entry) {
            $address = $this->postAddresses->findPost($entry);
            if (null !== $address) {
                $addresses[$entryId] = $address;
            }
        }

        return $addresses;
    }

    private static function lemmyOf(Entry $entry, ?EntryLemmyPost $post, ?VoteScore $myVote): ?EntryListRowLemmy
    {
        if (null === $post && null === $myVote) {
            return null;
        }
        $community = $post?->getCommunity();
        $feedIdentity = ProfileFeedUrl::identityOf(SocialNetwork::Lemmy, $entry->getFeed()->getUrl());
        $inOwnFeed = null !== $feedIdentity && strtolower($feedIdentity) === $community?->identity;

        return new EntryListRowLemmy($post?->getScore(), $inOwnFeed ? null : $community, $myVote);
    }
}
