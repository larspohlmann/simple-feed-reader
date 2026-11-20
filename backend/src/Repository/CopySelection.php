<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;

/** Which copies a read-state spread reaches: a SELECT of `copy` rows over `entry`, with its bound values. */
final readonly class CopySelection
{
    /**
     * @param list<int>                                $sourceIds the entries or feeds the copies are found from
     * @param array<string, mixed>                     $parameters
     * @param array<string, ArrayParameterType|string> $types
     */
    private function __construct(
        public string $sql,
        private array $sourceIds,
        public array $parameters,
        public array $types,
    ) {
    }

    /** @param list<int> $entryIds */
    public static function fromItemSources(array $entryIds): self
    {
        return self::fromSourceEntries(
            'JOIN entry copy ON copy.canonical_item_id = source.canonical_item_id',
            $entryIds,
        );
    }

    /**
     * Every entry at the address, item or not: the list collapse groups by address alone.
     *
     * @param list<int> $entryIds
     */
    public static function fromAddressSources(array $entryIds): self
    {
        return self::fromSourceEntries('JOIN entry copy ON copy.url_hash = source.url_hash', $entryIds);
    }

    /** @param list<int> $feedIds */
    public static function fromSweptFeeds(array $feedIds, \DateTimeImmutable $until): self
    {
        return new self(
            'SELECT copy.id, copy.feed_id, copy.effective_date FROM entry source'
            . ' JOIN entry copy ON copy.canonical_item_id = source.canonical_item_id'
            . ' WHERE source.canonical_item_id IS NOT NULL AND source.feed_id IN (:feeds)'
            . ' AND source.effective_date <= :until',
            $feedIds,
            ['feeds' => $feedIds, 'until' => $until],
            ['feeds' => ArrayParameterType::INTEGER, 'until' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function isEmpty(): bool
    {
        return $this->sourceIds === [];
    }

    /** @param list<int> $entryIds */
    private static function fromSourceEntries(string $copyJoin, array $entryIds): self
    {
        return new self(
            'SELECT copy.id, copy.feed_id, copy.effective_date FROM entry source ' . $copyJoin
            . ' WHERE source.id IN (:sources) AND copy.id NOT IN (:sources)',
            $entryIds,
            ['sources' => $entryIds],
            ['sources' => ArrayParameterType::INTEGER],
        );
    }
}
