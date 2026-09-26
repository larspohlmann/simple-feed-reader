<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\CommentsLoad;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Url\UrlNormalizer;
use Doctrine\DBAL\Connection;

/**
 * Multi-row INSERTs into `entry`, 500 rows a statement: 14× faster than the ORM at restore scale (spec appendix).
 * url_hash is recomputed, never read from the file (#556); dates bind as the naive-UTC strings Doctrine stores.
 */
final readonly class EntryBatchInserter
{
    private const int ROWS_PER_STATEMENT = 500;

    private const array COLUMNS = [
        'feed_id', 'guid', 'guid_hash', 'url', 'url_hash', 'title', 'author',
        'summary', 'content_html', 'image_url', 'image_width', 'image_height',
        'media', 'attachments',
        'published_at', 'created_at', 'effective_date',
        'discussion_url', 'comments_feed_url', 'comments_load', 'body_is_opening_post',
    ];

    public function __construct(
        private Connection $connection,
        private UrlNormalizer $urlNormalizer,
    ) {
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
            $feedId, $line->guid, $line->guidHash, $line->url,
            $this->urlNormalizer->hash($line->url), $line->title,
            $line->author, $line->summary, $line->contentHtml, $line->imageUrl,
            $line->imageWidth, $line->imageHeight,
            self::encodeList($line->media), self::encodeList($line->attachments),
            self::storageDate($line->publishedAt),
            self::storageDate($line->createdAt),
            self::storageDate($line->effectiveDate),
            $line->discussionUrl, $line->commentsFeedUrl,
            CommentsLoad::tryFrom((string) $line->commentsLoad)?->value,
            (int) $line->bodyIsOpeningPost,
        ];
    }

    /**
     * Re-encodes a media list to the JSON the ORM's json type reads back — null
     * for an empty list, matching the "no media" case a fresh ingest persists.
     *
     * @param list<array<string, mixed>> $list
     */
    private static function encodeList(array $list): ?string
    {
        return $list === [] ? null : json_encode($list, \JSON_THROW_ON_ERROR);
    }

    private static function storageDate(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }
}
