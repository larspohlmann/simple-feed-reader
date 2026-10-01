<?php

declare(strict_types=1);

namespace App\Entity;

/** The JSON-list columns of an embeddable: a list persists as null when empty, and reads back only its complete rows. */
final class StoredList
{
    /**
     * @template T of object
     *
     * @param list<array<string, mixed>>|null              $stored
     * @param \Closure(array<string, mixed>): bool         $isComplete
     * @param \Closure(array<string, mixed>): T            $fromStored
     *
     * @return list<T>
     */
    public static function read(?array $stored, \Closure $isComplete, \Closure $fromStored): array
    {
        return array_values(array_map($fromStored, array_filter($stored ?? [], $isComplete)));
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param list<T> $encoded
     *
     * @return list<T>|null
     */
    public static function orNull(array $encoded): ?array
    {
        return $encoded === [] ? null : $encoded;
    }
}
