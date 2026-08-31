<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Draws the audit's article sample from one user's subscriptions, stratified so
 * that every subscribed feed is represented: each feed contributes its own
 * shuffled candidates, and the round-robin hands out one article per feed
 * before it hands any feed a second. A plain "ORDER BY random LIMIT 1000" over
 * 31k entries would spend most of the budget on the few feeds that publish most
 * — exactly the feeds whose cleaners are already known to work.
 *
 * The shuffle is seeded in PHP rather than by the database, so the same seed
 * draws the same sample on MySQL and SQLite and parallel shards of one run can
 * each recompute the identical list without a shared file. A seed alone is not
 * enough for that: the refresh worker keeps ingesting during a sweep, and a
 * changed candidate set reshuffles into a different sample. So the caller also
 * fixes a cutoff instant, and every shard draws from the entries that existed
 * when the sweep began.
 */
final readonly class AuditSampler
{
    public function __construct(private Connection $connection)
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
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.feed_id AS feed_id, e.id AS entry_id
               FROM subscription s
               JOIN entry e ON e.feed_id = s.feed_id
              WHERE s.user_id = :user AND e.url IS NOT NULL AND e.url <> \'\'
                AND e.created_at < :before
              ORDER BY s.feed_id, e.id',
            ['user' => $userId, 'before' => $before->format('Y-m-d H:i:s')],
        );

        $byFeed = [];
        foreach ($rows as $row) {
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
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.title, e.url, e.content_html, e.image_url, f.id AS feed_id,
                    f.title AS feed_title, f.url AS feed_url, s.id AS subscription_id
               FROM entry e
               JOIN feed f ON f.id = e.feed_id
               JOIN subscription s ON s.feed_id = f.id AND s.user_id = :user
              WHERE e.id IN (:ids)',
            ['user' => $userId, 'ids' => $entryIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $byId = [];
        foreach ($rows as $row) {
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
                feedContentHtml: DatabaseValue::nullableString($row['content_html']),
                hasFeedImage: DatabaseValue::isPresent($row['image_url']),
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
