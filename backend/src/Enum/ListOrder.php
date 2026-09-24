<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum ListOrder: string
{
    case NewestFirst = 'desc';
    case OldestFirst = 'asc';

    /**
     * @throws ValidationException when the value names neither order
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::NewestFirst;
        }

        return self::tryFrom($value)
            ?? throw new ValidationException(['order' => ['Unknown order. Use one of: desc, asc.']]);
    }

    public function sqlDirection(): string
    {
        return match ($this) {
            self::NewestFirst => 'DESC',
            self::OldestFirst => 'ASC',
        };
    }

    /** How a later row's instant compares to an earlier row's in this order. */
    public function strictlyAfter(): string
    {
        return match ($this) {
            self::NewestFirst => '<',
            self::OldestFirst => '>',
        };
    }

    /** How an instant at or before a bound in this order compares to the bound. */
    public function atOrBefore(): string
    {
        return match ($this) {
            self::NewestFirst => '>=',
            self::OldestFirst => '<=',
        };
    }

    /**
     * @template T
     *
     * @param list<T> $newestFirst
     *
     * @return list<T>
     */
    public function arrange(array $newestFirst): array
    {
        return $this === self::OldestFirst ? array_reverse($newestFirst) : $newestFirst;
    }
}
