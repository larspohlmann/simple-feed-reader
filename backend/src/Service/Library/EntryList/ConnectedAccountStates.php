<?php

declare(strict_types=1);

namespace App\Service\Library\EntryList;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\LemmyPost;
use App\Entity\SocialAccount;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use App\Repository\CanonicalItemRepository;
use App\Repository\DiscussionVoteRepository;
use App\Repository\EntryListRowLemmy;
use App\Repository\EntryListRowSocial;
use App\Repository\LemmyPostRepository;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialLikeRepository;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Library\EntryList\Model\ConnectedAccountStatesModel;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\ProfileFeeds;
use App\Service\Social\Social\SocialPostAddresses;

/** What a page of entries looks like to the reader's connected accounts: likes, own posts and Lemmy votes. */
final readonly class ConnectedAccountStates
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialLikeRepository $socialLikes,
        private DiscussionVoteRepository $discussionVotes,
        private CanonicalItemRepository $canonicalItems,
        private LemmyPostRepository $lemmyPosts,
        private SocialPostAddresses $postAddresses,
        private ProfileFeeds $profileFeeds,
    ) {
    }

    /** @param array<int, Entry> $entries by entry id */
    public function buildStates(array $entries, int $userId): ConnectedAccountStatesModel
    {
        $postAddresses = $this->collectPostAddresses($entries);
        $lemmyPostIds = self::collectLemmyPostIds($entries);
        $accountsByNetwork = $this->loadAccountsByNetwork($postAddresses, $lemmyPostIds, $userId);
        $socialStates = $this->buildSocialStates($entries, $postAddresses, $accountsByNetwork, $userId);
        $votes = $this->loadVotes($lemmyPostIds, $accountsByNetwork, $userId);
        $itemIds = self::collectItemIds($entries);
        $items = $this->canonicalItems->findByIds($itemIds);
        $lemmyPosts = $this->lemmyPosts->byCanonicalItemIds($itemIds);

        $lemmyStates = [];
        foreach ($entries as $entryId => $entry) {
            $itemId = $entry->getCanonicalItem()?->getId();
            $lemmy = $this->buildLemmyRow(
                $entry,
                null === $itemId ? null : ($items[$itemId] ?? null),
                null === $itemId ? null : ($lemmyPosts[$itemId] ?? null),
                $votes[$entryId] ?? null,
            );
            if (null !== $lemmy) {
                $lemmyStates[$entryId] = $lemmy;
            }
        }

        return new ConnectedAccountStatesModel($socialStates, $lemmyStates);
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return list<int>
     */
    private static function collectItemIds(array $entries): array
    {
        $itemIds = [];
        foreach ($entries as $entry) {
            $itemId = $entry->getCanonicalItem()?->getId();
            if (null !== $itemId) {
                $itemIds[$itemId] = $itemId;
            }
        }

        return array_values($itemIds);
    }

    /**
     * @param array<int, SocialPostAddressModel> $postAddresses
     * @param list<int> $lemmyPostIds
     *
     * @return array<string, non-empty-list<SocialAccount>> by network value; read only when a post can use them
     */
    private function loadAccountsByNetwork(array $postAddresses, array $lemmyPostIds, int $userId): array
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
    private function loadVotes(array $lemmyPostIds, array $accountsByNetwork, int $userId): array
    {
        if (!isset($accountsByNetwork[SocialNetwork::Lemmy->value])) {
            return [];
        }

        $stored = $this->discussionVotes->findScores($userId, $lemmyPostIds);

        return $stored + array_fill_keys($lemmyPostIds, VoteScore::None);
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return list<int>
     */
    private static function collectLemmyPostIds(array $entries): array
    {
        return array_keys(array_filter(
            $entries,
            static fn (Entry $entry): bool => null !== LemmyPostAddress::findPost($entry),
        ));
    }

    /**
     * @param array<int, Entry> $entries
     * @param array<int, SocialPostAddressModel> $posts
     * @param array<string, non-empty-list<SocialAccount>> $accountsByNetwork
     *
     * @return array<int, EntryListRowSocial> by entry id, for every post
     */
    private function buildSocialStates(array $entries, array $posts, array $accountsByNetwork, int $userId): array
    {
        if ([] === $posts) {
            return [];
        }
        $likeable = array_filter(
            $posts,
            static fn (SocialPostAddressModel $post): bool => isset($accountsByNetwork[$post->network->value]),
        );
        $likedIds = $this->socialLikes->findLikedEntryIds($userId, array_keys($likeable));

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
    private function collectPostAddresses(array $entries): array
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

    private function buildLemmyRow(
        Entry $entry,
        ?CanonicalItem $item,
        ?LemmyPost $post,
        ?VoteScore $myVote,
    ): ?EntryListRowLemmy {
        $score = CanonicalItemKind::Lemmy === $item?->getKind() ? $item->getScore() : null;
        if (null === $post && null === $score && null === $myVote) {
            return null;
        }
        $community = $post?->getCommunity();
        $feedUrl = $entry->getFeed()->getUrl();
        $feedIdentity = $this->profileFeeds->selectRule(SocialNetwork::Lemmy)->parseIdentity($feedUrl);
        $inOwnFeed = null !== $feedIdentity && strtolower($feedIdentity) === $community?->identity;

        return new EntryListRowLemmy($score, $inOwnFeed ? null : $community, $myVote);
    }
}
