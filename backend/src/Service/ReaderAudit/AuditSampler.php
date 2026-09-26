<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Repository\ReaderAuditRepository;

/**
 * Draws the audit sample stratified by feed: every feed gives one article before any gives a second. The shuffle is
 * seeded in PHP, so every shard draws the same sample on MySQL and SQLite; the caller's cutoff fixes the entry set.
 */
final readonly class AuditSampler
{
    public function __construct(private ReaderAuditRepository $audit)
    {
    }

    /** @return list<SampledEntry> */
    public function sample(AuditSample $request): array
    {
        $byFeed = $this->candidatesByFeed($request->userId, $request->seed, $request->before);
        $chosenIds = $this->roundRobin($byFeed, $request->limit, $request->perFeed);

        return $chosenIds === [] ? [] : $this->detailsOf($chosenIds, $request->userId);
    }

    /**
     * The named articles instead of a draw — how a cleaner change is re-checked
     * against the pages that motivated it.
     *
     * @param list<int> $entryIds
     *
     * @return list<SampledEntry>
     */
    public function pick(array $entryIds, int $userId): array
    {
        return $entryIds === [] ? [] : $this->detailsOf($entryIds, $userId);
    }

    /**
     * Candidate entry ids per feed, each feed's list already shuffled.
     *
     * @return array<int, list<int>>
     */
    private function candidatesByFeed(int $userId, int $seed, \DateTimeImmutable $before): array
    {
        $byFeed = [];
        foreach ($this->audit->candidateRows($userId, $before) as $row) {
            $byFeed[DatabaseValue::int($row['feed_id'])][] = DatabaseValue::int($row['entry_id']);
        }

        mt_srand($seed);
        foreach ($byFeed as $feedId => $entryIds) {
            shuffle($entryIds);
            $byFeed[$feedId] = $entryIds;
        }

        return $byFeed;
    }

    /**
     * @param array<int, list<int>> $byFeed
     *
     * @return list<int>
     */
    private function roundRobin(array $byFeed, int $limit, int $perFeed): array
    {
        $chosen = [];
        for ($round = 0; $round < $perFeed; $round++) {
            foreach ($byFeed as $entryIds) {
                if (!isset($entryIds[$round])) {
                    continue;
                }
                $chosen[] = $entryIds[$round];
                if (\count($chosen) >= $limit) {
                    return $chosen;
                }
            }
        }

        return $chosen;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<SampledEntry>
     */
    private function detailsOf(array $entryIds, int $userId): array
    {
        $byId = [];
        foreach ($this->audit->detailRows($entryIds, $userId) as $row) {
            $entryId = DatabaseValue::int($row['id']);
            $byId[$entryId] = new SampledEntry(
                entryId: $entryId,
                subscriptionId: DatabaseValue::int($row['subscription_id']),
                feedId: DatabaseValue::int($row['feed_id']),
                // A feed the publisher never titled is still a feed to audit; the
                // report names it by its URL rather than dropping it.
                feedTitle: DatabaseValue::nullableString($row['feed_title'])
                    ?? DatabaseValue::string($row['feed_url']),
                title: DatabaseValue::string($row['title']),
                url: DatabaseValue::string($row['url']),
                feedContentHtml: DatabaseValue::nullableString($row['article_content_html']),
                hasFeedImage: DatabaseValue::isPresent($row['image_url']),
                author: DatabaseValue::nullableString($row['author']),
            );
        }

        // Re-ordered to the round-robin order the caller drew, which is what the
        // shard split slices on.
        $ordered = [];
        foreach ($entryIds as $entryId) {
            if (isset($byId[$entryId])) {
                $ordered[] = $byId[$entryId];
            }
        }

        return $ordered;
    }
}
