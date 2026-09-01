<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/**
 * One DQL result row of the shared entry-list projection, turned into an
 * EntryListRow. A pure array-to-value-object mapping; needs nothing injected.
 */
final readonly class EntryListRowHydrator
{
    /**
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => Entry, scalars...]
     */
    public function hydrate(array $row): EntryListRow
    {
        /** @var Entry $entry */
        $entry = $row[0];

        return new EntryListRow(
            entry: $entry,
            subscriptionId: self::toInt($row['subscriptionId']),
            subscriptionTitle: $this->rowTitle($row),
            isHidden: $this->rowIsHidden($row, $entry),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            isViewed: (bool) ($row['esViewed'] ?? false),
            viewedAt: $row['esViewedAt'] instanceof \DateTimeImmutable
                ? $row['esViewedAt']
                : null,
            markedReadUntil: $row['markedReadUntil'] instanceof \DateTimeImmutable
                ? $row['markedReadUntil']
                : null,
        );
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowIsHidden(array $row, Entry $entry): bool
    {
        $esHidden = $row['esHidden'];
        $markedReadUntil = $row['markedReadUntil'];

        return EffectiveReadState::isHidden(
            $esHidden === null ? null : (bool) $esHidden,
            $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
            $entry->getEffectiveDate(),
        );
    }

    /**
     * The subscription's display title: its custom override, else the feed
     * title, else the bare feed URL as a last resort.
     *
     * @param array<array-key, mixed> $row
     */
    private function rowTitle(array $row): string
    {
        $customTitle = $row['customTitle'];
        $feedTitle = $row['feedTitle'];
        $feedUrl = $row['feedUrl'];

        return SubscriptionDisplayTitle::from(
            \is_string($customTitle) ? $customTitle : null,
            \is_string($feedTitle) ? $feedTitle : null,
            \is_string($feedUrl) ? $feedUrl : '',
        );
    }
}
