<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Trending\Model\WindowEntryModel;
use Doctrine\ORM\EntityManagerInterface;

/** The rebuild's read: a window's entries with only the columns Trending matches on, a chunk at a time (#2017). */
final readonly class TrendingEntryRepository
{
    private const int CHUNK_ROWS = 1_000;

    /** A summary is cut to 300 characters after its markup is stripped; this bounds what a chunk holds before that. */
    private const int SUMMARY_CHARACTERS = 2_000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return iterable<WindowEntryModel> every entry dated at or after $since, in id order */
    public function windowEntries(\DateTimeImmutable $since): iterable
    {
        $afterId = 0;
        do {
            $rows = $this->chunkAfter($since, $afterId);
            foreach ($rows as $row) {
                $entry = self::modelOf($row);
                $afterId = $entry->id;
                yield $entry;
            }
        } while (\count($rows) === self::CHUNK_ROWS);
    }

    /**
     * @return list<array{
     *   id: int|string, feed_id: int|string, title: string, title_derived: bool|int|string, summary: string|null,
     *   effective_date: string, url_hash: string|null, link_card_url: string|null
     * }>
     */
    private function chunkAfter(\DateTimeImmutable $since, int $afterId): array
    {
        /**
         * @var list<array{
         *   id: int|string, feed_id: int|string, title: string, title_derived: bool|int|string,
         *   summary: string|null, effective_date: string, url_hash: string|null, link_card_url: string|null
         * }> $rows
         */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            \sprintf(
                'SELECT e.id, e.feed_id, e.title, e.title_derived, SUBSTR(e.summary, 1, %d) AS summary,'
                . ' e.effective_date, e.url_hash,'
                . ' (SELECT card.url FROM link_card card WHERE card.entry_id = e.id AND card.thread_post_id IS NULL'
                . ' ORDER BY card.position LIMIT 1) AS link_card_url'
                . ' FROM entry e WHERE e.effective_date >= :since AND e.id > :afterId ORDER BY e.id LIMIT %d',
                self::SUMMARY_CHARACTERS,
                self::CHUNK_ROWS,
            ),
            ['since' => $since->format('Y-m-d H:i:s'), 'afterId' => $afterId],
        );

        return $rows;
    }

    /**
     * @param array{
     *   id: int|string, feed_id: int|string, title: string, title_derived: bool|int|string, summary: string|null,
     *   effective_date: string, url_hash: string|null, link_card_url: string|null
     * } $row
     */
    private static function modelOf(array $row): WindowEntryModel
    {
        return new WindowEntryModel(
            (int) $row['id'],
            (int) $row['feed_id'],
            $row['title'],
            (bool) (int) $row['title_derived'],
            $row['summary'],
            new \DateTimeImmutable($row['effective_date'], new \DateTimeZone('UTC')),
            $row['url_hash'],
            $row['link_card_url'],
        );
    }
}
