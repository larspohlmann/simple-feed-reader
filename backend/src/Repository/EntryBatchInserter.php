<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryMedia;
use App\Entity\QuotedPostContent;
use App\Enum\CommentsLoad;
use App\Enum\ShareKind;
use App\Service\Backup\Dto\EntryLine;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Service\Url\UrlNormalizer;
use Doctrine\DBAL\Connection;

/**
 * Multi-row INSERTs into `entry`, 500 rows a statement: 14× faster than the ORM at restore scale (spec appendix).
 * url_hash is recomputed, never read from the file (#556); dates bind as the naive-UTC strings Doctrine stores.
 */
final readonly class EntryBatchInserter
{
    private const int ROWS_PER_STATEMENT = 500;
    private const int CARDS_PER_STATEMENT = 100;

    private const array COLUMNS = [
        'feed_id', 'guid', 'guid_hash', 'url', 'url_hash', 'title', 'author',
        'summary', 'content_html', 'image_url', 'image_width', 'image_height', 'image_renditions',
        'media', 'attachments', 'chapters', 'chapters_url',
        'published_at', 'created_at', 'effective_date',
        'discussion_url', 'comments_feed_url', 'comments_load',
        'title_derived', 'share_kind', 'author_url', 'author_avatar_url',
        'quoted_url', 'quoted_author_name', 'quoted_author_handle', 'quoted_author_avatar_url', 'quoted_posted_at',
        'quoted_text', 'quoted_media', 'quoted_nested_url', 'quoted_nested_handle',
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

    /**
     * The cards of entries just inserted, which hold none yet.
     *
     * @param array<string, int> $entryIdsByGuidHash
     * @param list<EntryLine>    $lines
     */
    public function insertLinkCards(array $entryIdsByGuidHash, array $lines): void
    {
        $rows = [];
        foreach ($lines as $line) {
            foreach ($line->linkCards as $position => $card) {
                $rows[] = [
                    $entryIdsByGuidHash[$line->guidHash], $position,
                    $card->url, $card->title, $card->description, $card->imageUrl, $card->siteName,
                ];
            }
        }
        $this->insertRows('link_card (entry_id, position, url, title, description, image_url, site_name)', $rows);
    }

    /**
     * The origins of saved copies just inserted; any other feed keeps none.
     *
     * @param array<string, int> $entryIdsByGuidHash
     * @param list<EntryLine>    $lines
     */
    public function insertOrigins(array $entryIdsByGuidHash, array $lines, string $feedUrl): void
    {
        if (!SavedFeedUrl::isSaved($feedUrl)) {
            return;
        }

        $rows = [];
        foreach ($lines as $line) {
            if (null !== $line->origin) {
                $rows[] = [$entryIdsByGuidHash[$line->guidHash], $line->origin->title, $line->origin->faviconUrl];
            }
        }
        $this->insertRows('saved_entry_origin (entry_id, title, favicon_url)', $rows);
    }

    /** @param list<non-empty-list<int|string|null>> $rows one value per column */
    private function insertRows(string $tableAndColumns, array $rows): void
    {
        foreach (array_chunk($rows, self::CARDS_PER_STATEMENT) as $chunk) {
            $rowPlaceholders = '(' . implode(', ', array_fill(0, \count($chunk[0]), '?')) . ')';
            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO %s VALUES %s',
                    $tableAndColumns,
                    implode(', ', array_fill(0, \count($chunk), $rowPlaceholders)),
                ),
                array_merge(...$chunk),
            );
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
            $line->imageWidth, $line->imageHeight, self::encodeList($line->imageRenditions),
            self::encodeList($line->media), self::encodeList($line->attachments),
            self::encodeList($line->chapters), $line->chaptersUrl,
            self::storageDate($line->publishedAt),
            self::storageDate($line->createdAt),
            self::storageDate($line->effectiveDate),
            $line->discussionUrl, $line->commentsFeedUrl,
            CommentsLoad::tryFrom((string) $line->commentsLoad)?->value,
            (int) $line->titleDerived,
            ShareKind::tryFrom((string) $line->shareKind)?->value,
            $line->authorUrl, $line->authorAvatarUrl,
            ...self::quoteColumns($line->quote),
        ];
    }

    /** @return list<int|string|null> */
    private static function quoteColumns(?QuotedPostContent $quote): array
    {
        if ($quote === null) {
            return array_fill(0, 9, null);
        }

        return [
            $quote->url, $quote->authorName, $quote->authorHandle, $quote->authorAvatarUrl,
            self::storageDate($quote->postedAt), $quote->text,
            self::encodeList(EntryMedia::toJsonList($quote->media)),
            $quote->nestedQuote?->url, $quote->nestedQuote?->handle,
        ];
    }

    /**
     * Re-encodes a list to the JSON the ORM's json type reads back — null for an
     * empty list, matching the "none" case a fresh ingest persists.
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
