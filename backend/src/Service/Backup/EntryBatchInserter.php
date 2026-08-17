<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\EntryLine;
use Doctrine\DBAL\Connection;

/**
 * Multi-row INSERTs into `entry`, 500 rows per statement — the measured
 * 0.085 ms/row path (row-by-row through the ORM is 14× slower at restore
 * scale, see the spec appendix). Raw SQL by necessity, which is exactly why
 * guid_hash travels IN the backup file: no Entry constructor runs here, so
 * nothing could recompute it.
 *
 * The column list is spelled out once; values bind positionally per row.
 * Dates are formatted as the naive-UTC wall-clock strings Doctrine's
 * datetime_immutable type stores — every EntryLine date is already UTC
 * (LineField normalises on parse).
 */
final readonly class EntryBatchInserter
{
    private const int ROWS_PER_STATEMENT = 500;

    private const array COLUMNS = [
        'feed_id', 'guid', 'guid_hash', 'url', 'title', 'author', 'summary',
        'content_html', 'image_url', 'image_width', 'image_height',
        'published_at', 'created_at', 'effective_date',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @param list<EntryLine> $lines */
    public function insert(int $feedId, array $lines): void
    {
        foreach (array_chunk($lines, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->insertChunk($feedId, $chunk);
        }
    }

    /** @param non-empty-list<EntryLine> $chunk */
    private function insertChunk(int $feedId, array $chunk): void
    {
        $rowPlaceholders = '(' . implode(', ', array_fill(0, \count(self::COLUMNS), '?')) . ')';
        $sql = sprintf(
            'INSERT INTO entry (%s) VALUES %s',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, \count($chunk), $rowPlaceholders)),
        );

        $values = [];
        foreach ($chunk as $line) {
            array_push($values, ...$this->row($feedId, $line));
        }
        $this->connection->executeStatement($sql, $values);
    }

    /** @return list<int|string|null> */
    private function row(int $feedId, EntryLine $line): array
    {
        return [
            $feedId, $line->guid, $line->guidHash, $line->url, $line->title,
            $line->author, $line->summary, $line->contentHtml, $line->imageUrl,
            $line->imageWidth, $line->imageHeight,
            self::storageDate($line->publishedAt),
            self::storageDate($line->createdAt),
            self::storageDate($line->effectiveDate),
        ];
    }

    private static function storageDate(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }
}
