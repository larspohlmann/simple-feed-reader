<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\MemberHeadlineModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/** Trending's reads of a window's entries: the rebuild's chunks and a reader's active feeds (#2017). */
final readonly class TrendingEntryRepository
{
    private const int CHUNK_ROWS = 1_000;

    private const int LOOKUP_IDS = 120;

    /** A body is cut to 300 characters after its markup is stripped; this bounds what a chunk holds before that. */
    private const int BODY_SOURCE_CHARACTERS = 2_000;

    /** A card's description is plain text far more often than markup, so less headroom over 300 is kept. */
    private const int CARD_DESCRIPTION_CHARACTERS = 600;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return iterable<WindowEntryModel> every entry of the user's Trending feeds dated at or after $since, in id order */
    public function windowEntries(int $userId, \DateTimeImmutable $since): iterable
    {
        $afterId = 0;
        do {
            $rows = $this->chunkAfter($userId, $since, $afterId);
            foreach ($rows as $row) {
                $entry = self::modelOf($row);
                $afterId = $entry->id;
                yield $entry;
            }
        } while (\count($rows) === self::CHUNK_ROWS);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, MemberHeadlineModel> by entry id
     */
    public function headlinesOf(array $entryIds): array
    {
        $headlines = [];
        foreach (array_chunk($entryIds, self::LOOKUP_IDS) as $chunk) {
            /** @var list<array{id: int|string, title: string, feed_title: string|null}> $rows */
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT entry.id, entry.title, feed.title AS feed_title FROM entry JOIN feed ON feed.id = entry.feed_id'
                . ' WHERE entry.id IN (?)',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $entryId = (int) $row['id'];
                $headlines[$entryId] = new MemberHeadlineModel($entryId, $row['title'], $row['feed_title']);
            }
        }

        return $headlines;
    }

    /**
     * @param list<int> $sharedLinkIds
     *
     * @return array<int, string> the title of every link that has a non-blank one, by link id
     */
    public function linkTitlesOf(array $sharedLinkIds): array
    {
        $titles = [];
        foreach (array_chunk($sharedLinkIds, self::LOOKUP_IDS) as $chunk) {
            /** @var array<int, string> $chunkTitles */
            $chunkTitles = $this->entityManager->getConnection()->fetchAllKeyValue(
                'SELECT id, title FROM shared_link WHERE title IS NOT NULL AND id IN (?)',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );
            $titles += array_filter($chunkTitles, static fn (string $title): bool => trim($title) !== '');
        }

        return $titles;
    }

    public function hasEntriesCreatedAfter(int $userId, \DateTimeImmutable $after): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM subscription included WHERE ' . self::includedByUser()
            . ' AND EXISTS (SELECT 1 FROM entry created WHERE created.feed_id = included.feed_id'
            . ' AND created.created_at > :after)',
            ['user' => $userId, 'after' => $after->format('Y-m-d H:i:s')],
            ['user' => ParameterType::INTEGER],
        );

        return $found !== false;
    }

    /** How many of the user's Trending feeds have an entry at or after $since in no story the user hid. */
    public function activeFeedCount(int $userId, \DateTimeImmutable $since): int
    {
        $activeFeeds = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM subscription included WHERE ' . self::includedByUser()
            . ' AND EXISTS (SELECT 1 FROM entry recent'
            . ' WHERE recent.feed_id = included.feed_id AND recent.effective_date >= :since'
            . ' AND NOT EXISTS (SELECT 1 FROM trending_story_entry hidden_member JOIN trending_story_hide hidden'
            . ' ON hidden.story_id = hidden_member.story_id AND hidden.user_id = :user'
            . ' WHERE hidden_member.entry_id = recent.id))',
            ['user' => $userId, 'since' => $since->format('Y-m-d H:i:s')],
            ['user' => ParameterType::INTEGER],
        );

        \assert(is_numeric($activeFeeds));

        return (int) $activeFeeds;
    }

    /**
     * @return list<array{
     *   id: int|string, feed_id: int|string, language: string|null, title: string, title_derived: bool|int|string,
     *   summary: string|null, effective_date: string, url_hash: string|null, article_url: string|null,
     *   card_link_id: int|string|null, card_url_hash: string|null, card_url: string|null, card_title: string|null,
     *   card_description: string|null, card_site_name: string|null, card_language: string|null
     * }>
     */
    private function chunkAfter(int $userId, \DateTimeImmutable $since, int $afterId): array
    {
        /**
         * @var list<array{
         *   id: int|string, feed_id: int|string, language: string|null, title: string, title_derived: bool|int|string,
         *   summary: string|null, effective_date: string, url_hash: string|null, article_url: string|null,
         *   card_link_id: int|string|null, card_url_hash: string|null, card_url: string|null, card_title: string|null,
         *   card_description: string|null, card_site_name: string|null, card_language: string|null
         * }> $rows
         */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            \sprintf(
                'SELECT e.id, e.feed_id, COALESCE(e.language, f.language) AS language, e.title, e.title_derived,'
                . ' SUBSTR(e.summary, 1, %d) AS summary,'
                . ' e.effective_date, e.url_hash, CASE WHEN f.network IS NULL THEN e.url END AS article_url,'
                . ' link.id AS card_link_id, ' . LinkLandingSql::URL_HASH . ' AS card_url_hash,'
                . ' ' . LinkLandingSql::URL . ' AS card_url,'
                . ' link.title AS card_title, link.site_name AS card_site_name, link.language AS card_language,'
                . ' SUBSTR(link.description, 1, %d) AS card_description'
                . ' FROM entry e JOIN feed f ON f.id = e.feed_id'
                . ' JOIN subscription included ON included.feed_id = e.feed_id AND ' . self::includedByUser()
                . ' LEFT JOIN link_card card ON card.entry_id = e.id AND' . LinkLandingSql::FIRST_ROOT_CARD
                . ' LEFT JOIN shared_link link ON link.id = card.shared_link_id'
                . LinkLandingSql::JOIN
                . ' WHERE e.effective_date >= :since AND e.id > :afterId ORDER BY e.id LIMIT %d',
                self::BODY_SOURCE_CHARACTERS,
                self::CARD_DESCRIPTION_CHARACTERS,
                self::CHUNK_ROWS,
            ),
            ['user' => $userId, 'since' => $since->format('Y-m-d H:i:s'), 'afterId' => $afterId],
            ['user' => ParameterType::INTEGER],
        );

        return $rows;
    }

    /**
     * @param array{
     *   id: int|string, feed_id: int|string, language: string|null, title: string, title_derived: bool|int|string,
     *   summary: string|null, effective_date: string, url_hash: string|null, article_url: string|null,
     *   card_link_id: int|string|null, card_url_hash: string|null, card_url: string|null, card_title: string|null,
     *   card_description: string|null, card_site_name: string|null, card_language: string|null
     * } $row
     */
    private static function modelOf(array $row): WindowEntryModel
    {
        return new WindowEntryModel(
            (int) $row['id'],
            (int) $row['feed_id'],
            $row['language'] === null ? null : FeedLanguage::from($row['language']),
            $row['title'],
            (bool) (int) $row['title_derived'],
            $row['summary'],
            NaiveUtc::parse($row['effective_date']),
            new EntryLinksModel($row['url_hash'], $row['article_url'], self::cardOf($row)),
        );
    }

    /**
     * @param array{
     *   card_link_id: int|string|null, card_url_hash: string|null, card_url: string|null, card_title: string|null,
     *   card_description: string|null, card_site_name: string|null, card_language: string|null
     * } $row
     */
    private static function cardOf(array $row): ?WindowLinkCardModel
    {
        if (
            $row['card_link_id'] === null || $row['card_url_hash'] === null || $row['card_url'] === null
            || $row['card_title'] === null || $row['card_site_name'] === null
        ) {
            return null;
        }

        return new WindowLinkCardModel(
            (int) $row['card_link_id'],
            $row['card_url_hash'],
            $row['card_url'],
            $row['card_title'],
            $row['card_description'],
            $row['card_site_name'],
            FeedLanguage::tryFrom((string) $row['card_language']),
        );
    }

    private static function includedByUser(): string
    {
        return 'included.user_id = :user AND ' . TrendingInclusionSql::includes('included');
    }
}
