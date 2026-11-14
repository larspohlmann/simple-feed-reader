<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\CommentsLoad;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Enum\VoteScore;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Content\Comments\Support\SubstackPostAddress;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\Support\OwnInstancePostUrl;
use App\Service\Social\Social\Support\PostAuthor;
use App\Service\Social\Social\Support\SocialPostAddress;

/**
 * Gives a page of rows their categories, saved searches, link cards, thread posts, pending flag, social state,
 * discussion and saved origin: one query per lookup, two for the thread posts and their cards.
 */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryRepository $categories,
        private SavedSearchEntryRepository $memberships,
        private LinkCardRepository $linkCards,
        private ShareEnrichmentRepository $shareEnrichments,
        private SocialAccountRepository $socialAccounts,
        private SocialLikeRepository $socialLikes,
        private ThreadPostRepository $threadPosts,
        private SavedEntryOriginRepository $savedOrigins,
        private DiscussionVoteRepository $discussionVotes,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function enrich(array $rows, int $userId): array
    {
        $enrichments = $this->enrichmentsByEntryId($rows, $userId);

        return array_map(static fn (EntryListRow $row): EntryListRow => $row->enrichedFrom($enrichments), $rows);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, EntryListRowEnrichment>
     */
    private function enrichmentsByEntryId(array $rows, int $userId): array
    {
        $entries = self::entriesById($rows);
        $entryIds = array_keys($entries);
        $categories = $this->categories->labelsByEntryIds($entryIds);
        $savedSearches = $this->memberships->savedSearchesByEntry($userId, $entryIds);
        $linkCards = $this->linkCards->contentsByEntryIds($entryIds);
        $threads = $this->threadPosts->byEntryIds($entryIds);
        $pendingIds = array_flip($this->shareEnrichments->pendingEntryIdsAmong(self::topLevelEntryIdsOf($rows)));
        $postAddresses = self::postAddressesOf($entries);
        $lemmyPostIds = self::lemmyPostIdsOf($entries);
        $accountsByNetwork = $this->accountsByNetworkOf($postAddresses, $lemmyPostIds, $userId);
        $socialStates = $this->socialStatesOf($entries, $postAddresses, $accountsByNetwork, $userId);
        $votes = $this->votesOf($lemmyPostIds, $accountsByNetwork, $userId);
        $origins = $this->savedOrigins->originsByEntryIds(self::savedEntryIdsOf($entries));

        $enrichments = [];
        foreach ($entryIds as $entryId) {
            $enrichments[$entryId] = new EntryListRowEnrichment(
                $categories[$entryId] ?? [],
                $savedSearches[$entryId] ?? [],
                $linkCards[$entryId] ?? [],
                isset($pendingIds[$entryId]),
                $socialStates[$entryId] ?? null,
                self::discussionOf($entries[$entryId], $socialStates[$entryId] ?? null),
                $origins[$entryId] ?? null,
                $threads[$entryId] ?? [],
                $votes[$entryId] ?? null,
            );
        }

        return $enrichments;
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
                isOwn: $canLike && null !== PostAuthor::accountAmong($entries[$entryId], $networkAccounts),
                openUrl: OwnInstancePostUrl::of($post, $networkAccounts),
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
    private static function postAddressesOf(array $entries): array
    {
        $addresses = [];
        foreach ($entries as $entryId => $entry) {
            $address = SocialPostAddress::of($entry);
            if (null !== $address) {
                $addresses[$entryId] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return list<int>
     */
    private static function savedEntryIdsOf(array $entries): array
    {
        return array_keys(array_filter(
            $entries,
            static fn (Entry $entry): bool => SourceFormat::isSaved($entry->getFeed()->getSourceFormat()),
        ));
    }

    private static function discussionOf(Entry $entry, ?EntryListRowSocial $social): ?EntryListRowDiscussion
    {
        $isPlatformThread = null !== $social || null !== SubstackPostAddress::of($entry);

        return $isPlatformThread ? new EntryListRowDiscussion($entry->getUrl(), CommentsLoad::Auto) : null;
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, Entry> the entries of these rows and of their duplicates, each once, by id
     */
    private static function entriesById(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                $entries[$member->entry->requireId()] = $member->entry;
            }
        }

        return $entries;
    }

    /**
     * Only a top-level row can be pending, never one of its duplicates.
     *
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private static function topLevelEntryIdsOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
    }
}
