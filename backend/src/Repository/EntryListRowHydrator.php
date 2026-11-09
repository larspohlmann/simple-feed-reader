<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

final readonly class EntryListRowHydrator
{
    /**
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => Entry, scalars...]
     */
    public function hydrate(array $row): EntryListRow
    {
        /** @var Entry $entry */
        $entry = $row[0];

        return $this->hydrateEntry($entry, $row);
    }

    /**
     * @param array<array-key, mixed> $row a mixed DQL result whose scalars follow the shared entry-list projection
     */
    public function hydrateEntry(Entry $entry, array $row): EntryListRow
    {
        $markedReadUntil = $row['markedReadUntil'] instanceof \DateTimeImmutable ? $row['markedReadUntil'] : null;

        return new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(self::toInt($row['subscriptionId']), $this->rowTitle($row)),
            marks: new EntryListRowMarks(
                isHidden: $this->rowIsHidden($row, $entry, $markedReadUntil),
                isFavorite: (bool) ($row['esFavorite'] ?? false),
                isKept: (bool) ($row['esKept'] ?? false),
                viewedAt: $row['esViewedAt'] instanceof \DateTimeImmutable ? $row['esViewedAt'] : null,
                sharedAt: $row['eshSharedAt'] instanceof \DateTimeImmutable ? $row['eshSharedAt'] : null,
            ),
            markedReadUntil: $markedReadUntil,
        );
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowIsHidden(array $row, Entry $entry, ?\DateTimeImmutable $markedReadUntil): bool
    {
        $esHidden = $row['esHidden'];

        return EffectiveReadState::isHidden(
            $esHidden === null ? null : (bool) $esHidden,
            $markedReadUntil,
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
