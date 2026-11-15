<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Foundation\Url\UrlNormalizer;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Merges every link card and quoted card into one shared_link per UrlNormalizer::hash() of its URL (#2239): the
 * oldest card wins (its entry's created_at, a card before a quote, then id), and no language is guessed here.
 * The hash is PHP, as the feed classification of Version20261114160000 is; ids are assigned here, so the table
 * must be empty. Version20261115140200 links the cards the old release wrote meanwhile through linkUnlinked().
 */
final class Version20261115140100 extends AbstractMigration
{
    private const int ROWS_PER_STATEMENT = 500;
    private const string BARE_URL = '~^https?://\S+$~';
    private const int CARD = 0;
    private const int QUOTE = 1;

    /** @var array<string, int> shared link id by url hash */
    private array $linkIds = [];

    /** @var list<list<int|string|null>> */
    private array $newRows = [];

    /** @var array<int, int> shared link id by card id */
    private array $cardLinks = [];

    /** @var array<int, list<int>> entry ids by shared link id */
    private array $quoteLinks = [];

    /** @var list<int> */
    private array $unhashedCards = [];

    private int $unhashedQuotes = 0;

    private int $malformedQuotes = 0;

    private int $firstNewId = 1;

    public function getDescription(): string
    {
        return 'Merge link cards and quoted cards into one shared_link per URL, the oldest card first (#2239)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM shared_link') > 0,
            'shared_link must be empty: this migration assigns its ids.',
        );

        $this->linkUnlinked();
    }

    /**
     * Links every card and quoted card that has no shared link yet, against the rows already stored (which are older,
     * so they win): the merge itself, and the slim migration's catch-up of cards written while the merge ran.
     */
    public function linkUnlinked(): void
    {
        $this->abortIfUnsupportedPlatform();
        foreach ($this->connection->fetchAllKeyValue('SELECT url_hash, id FROM shared_link') as $urlHash => $id) {
            $this->linkIds[(string) $urlHash] = (int) $id;
            $this->firstNewId = max($this->firstNewId, (int) $id + 1);
        }
        $normalizer = new UrlNormalizer();
        foreach ($this->oldestFirst() as $card) {
            $this->place($card, $normalizer->hash($card['url']));
        }

        $this->queueInserts();
        $this->queueCardPointers();
        $this->queueQuotePointers();
        if ($this->unhashedCards !== []) {
            $this->addSql(sprintf('DELETE FROM link_card WHERE id IN (%s)', implode(', ', $this->unhashedCards)));
        }
        $this->report();
    }

    private function report(): void
    {
        $this->write(sprintf(
            '%d shared links from %d cards and %d quoted cards; %d cards deleted and %d quoted cards dropped,'
            . ' their URL has no hash; %d quoted cards dropped, their stored JSON is no card.',
            \count($this->newRows),
            \count($this->cardLinks),
            array_sum(array_map(\count(...), $this->quoteLinks)),
            \count($this->unhashedCards),
            $this->unhashedQuotes,
            $this->malformedQuotes,
        ));
        $this->warnIf(
            $this->unhashedCards !== [] || $this->unhashedQuotes + $this->malformedQuotes > 0,
            'Cards or quoted cards were dropped (counts above); name them in the deploy note.',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();
        $this->addSql('UPDATE trending_story_entry SET shared_link_id = NULL');
        $this->addSql('UPDATE entry SET quoted_shared_link_id = NULL WHERE quoted_shared_link_id IS NOT NULL');
        $this->addSql('UPDATE link_card SET shared_link_id = NULL');
        $this->addSql('DELETE FROM shared_link');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /**
     * @return list<array{
     *   kind: int, id: int, createdAt: string, url: string, title: string, description: ?string,
     *   imageUrl: ?string, siteName: string
     * }>
     */
    private function oldestFirst(): array
    {
        $cards = [...$this->cards(), ...$this->quotedCards()];
        usort($cards, static fn (array $left, array $right): int => [$left['createdAt'], $left['kind'], $left['id']]
            <=> [$right['createdAt'], $right['kind'], $right['id']]);

        return $cards;
    }

    /**
     * @return list<array{
     *   kind: int, id: int, createdAt: string, url: string, title: string, description: ?string,
     *   imageUrl: ?string, siteName: string
     * }>
     */
    private function cards(): array
    {
        /**
         * @var list<array{
         *   id: int|string, created_at: string, url: string, title: string, description: ?string,
         *   image_url: ?string, site_name: string
         * }> $rows
         */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT card.id, entry.created_at, card.url, card.title, card.description, card.image_url, card.site_name'
            . ' FROM link_card card JOIN entry ON entry.id = card.entry_id WHERE card.shared_link_id IS NULL',
        );

        return array_map(static fn (array $row): array => [
            'kind' => self::CARD,
            'id' => (int) $row['id'],
            'createdAt' => $row['created_at'],
            'url' => $row['url'],
            'title' => $row['title'],
            'description' => $row['description'],
            'imageUrl' => $row['image_url'],
            'siteName' => $row['site_name'],
        ], $rows);
    }

    /**
     * @return list<array{
     *   kind: int, id: int, createdAt: string, url: string, title: string, description: ?string,
     *   imageUrl: ?string, siteName: string
     * }>
     */
    private function quotedCards(): array
    {
        /** @var list<array{id: int|string, created_at: string, quoted_link_card: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, created_at, quoted_link_card FROM entry'
            . ' WHERE quoted_link_card IS NOT NULL AND quoted_shared_link_id IS NULL',
        );
        $cards = [];
        foreach ($rows as $row) {
            $stored = json_decode($row['quoted_link_card'], true);
            if (!\is_array($stored) || !self::isACard($stored)) {
                ++$this->malformedQuotes;
                continue;
            }
            $cards[] = [
                'kind' => self::QUOTE,
                'id' => (int) $row['id'],
                'createdAt' => $row['created_at'],
                'url' => $stored['url'],
                'title' => $stored['title'],
                'description' => \is_string($stored['description'] ?? null) ? $stored['description'] : null,
                'imageUrl' => \is_string($stored['imageUrl'] ?? null) ? $stored['imageUrl'] : null,
                'siteName' => $stored['siteName'],
            ];
        }

        return $cards;
    }

    /** @param array<mixed> $stored */
    private static function isACard(array $stored): bool
    {
        return \is_string($stored['url'] ?? null) && \is_string($stored['title'] ?? null)
            && \is_string($stored['siteName'] ?? null);
    }

    /**
     * @param array{
     *   kind: int, id: int, createdAt: string, url: string, title: string, description: ?string,
     *   imageUrl: ?string, siteName: string
     * } $card
     */
    private function place(array $card, ?string $urlHash): void
    {
        if ($urlHash === null) {
            $card['kind'] === self::CARD ? $this->unhashedCards[] = $card['id'] : ++$this->unhashedQuotes;

            return;
        }
        if (!isset($this->linkIds[$urlHash])) {
            $this->linkIds[$urlHash] = $this->firstNewId + \count($this->newRows);
            $this->newRows[] = [
                $this->linkIds[$urlHash],
                $card['url'],
                $urlHash,
                $card['title'],
                self::withoutBareUrl($card['description']),
                $card['imageUrl'],
                $card['siteName'],
                $card['createdAt'],
            ];
        }
        if ($card['kind'] === self::CARD) {
            $this->cardLinks[$card['id']] = $this->linkIds[$urlHash];

            return;
        }
        $this->quoteLinks[$this->linkIds[$urlHash]][] = $card['id'];
    }

    private static function withoutBareUrl(?string $description): ?string
    {
        return $description !== null && preg_match(self::BARE_URL, trim($description)) === 1 ? null : $description;
    }

    private function queueInserts(): void
    {
        foreach (array_chunk($this->newRows, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->addSql(
                'INSERT INTO shared_link (id, url, url_hash, title, description, image_url, site_name, created_at)'
                . ' VALUES ' . implode(', ', array_map($this->valuesOf(...), $chunk)),
            );
        }
    }

    /** @param list<int|string|null> $row */
    private function valuesOf(array $row): string
    {
        return '(' . implode(', ', array_map(
            fn (int|string|null $value): string => match (true) {
                $value === null => 'NULL',
                \is_int($value) => (string) $value,
                default => $this->connection->quote($value),
            },
            $row,
        )) . ')';
    }

    private function queueCardPointers(): void
    {
        foreach (array_chunk($this->cardLinks, self::ROWS_PER_STATEMENT, true) as $chunk) {
            $cases = implode(' ', array_map(
                static fn (int $cardId, int $linkId): string => sprintf('WHEN %d THEN %d', $cardId, $linkId),
                array_keys($chunk),
                $chunk,
            ));
            $this->addSql(sprintf(
                'UPDATE link_card SET shared_link_id = CASE id %s END WHERE id IN (%s)',
                $cases,
                implode(', ', array_keys($chunk)),
            ));
        }
    }

    private function queueQuotePointers(): void
    {
        foreach ($this->quoteLinks as $linkId => $entryIds) {
            $this->addSql(sprintf(
                'UPDATE entry SET quoted_shared_link_id = %d WHERE id IN (%s)',
                $linkId,
                implode(', ', $entryIds),
            ));
        }
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            sprintf('No SQL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
