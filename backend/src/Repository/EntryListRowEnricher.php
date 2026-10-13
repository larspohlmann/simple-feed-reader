<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Social\Support\SocialFeedClassifier;
use App\Service\Social\Support\SocialPostAddress;

/**
 * Gives a page of rows their categories, saved searches, link cards, pending flag and social state: one query per
 * lookup.
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
        $pendingIds = array_flip($this->shareEnrichments->pendingEntryIdsAmong(self::topLevelEntryIdsOf($rows)));
        $socialStates = $this->socialStatesOf($entries, $userId);

        $enrichments = [];
        foreach ($entryIds as $entryId) {
            $enrichments[$entryId] = new EntryListRowEnrichment(
                $categories[$entryId] ?? [],
                $savedSearches[$entryId] ?? [],
                $linkCards[$entryId] ?? [],
                isset($pendingIds[$entryId]),
                $socialStates[$entryId] ?? null,
            );
        }

        return $enrichments;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, EntryListRowSocial> by entry id, only for posts on a network the user has an account on
     */
    private function socialStatesOf(array $entries, int $userId): array
    {
        $postNetworks = self::postNetworksOf($entries);
        if ([] === $postNetworks) {
            return [];
        }
        $accountNetworks = $this->socialAccounts->networksOf($userId);
        $networks = array_filter(
            $postNetworks,
            static fn (SocialNetwork $network): bool => \in_array($network, $accountNetworks, true),
        );
        $likedIds = $this->socialLikes->likedEntryIdsAmong($userId, array_keys($networks));

        $states = [];
        foreach ($networks as $entryId => $network) {
            $states[$entryId] = new EntryListRowSocial($network, isset($likedIds[$entryId]));
        }

        return $states;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, SocialNetwork> the network of each entry that is a social post, decided without a query
     */
    private static function postNetworksOf(array $entries): array
    {
        $feedNetworks = self::feedNetworksOf($entries);
        $networks = [];
        foreach ($entries as $entryId => $entry) {
            $feedNetwork = $feedNetworks[$entry->getFeed()->requireId()] ?? null;
            $network = SocialPostAddress::onFeedNetwork($entry, $feedNetwork)?->network;
            if (null !== $network) {
                $networks[$entryId] = $network;
            }
        }

        return $networks;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, SocialNetwork> by feed id, only for the feeds on a network
     */
    private static function feedNetworksOf(array $entries): array
    {
        $feeds = [];
        foreach ($entries as $entry) {
            $feed = $entry->getFeed();
            $feeds[$feed->requireId()] = $feed;
        }

        return array_filter(array_map(SocialFeedClassifier::networkOf(...), $feeds));
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
