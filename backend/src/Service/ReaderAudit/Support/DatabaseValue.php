<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Support;

use App\Service\ReaderAudit\Exception\UnexpectedDatabaseValueException;

/**
 * Reads one column of a DBAL row as the type the schema promises, so a renamed column fails on the first row
 * instead of silently sampling zeros.
 */
final readonly class DatabaseValue
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw self::unexpected('a number', $value);
    }

    public static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw self::unexpected('text', $value);
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function unexpected(string $expected, mixed $value): UnexpectedDatabaseValueException
    {
        return new UnexpectedDatabaseValueException(
            \sprintf('Expected %s, got %s.', $expected, get_debug_type($value)),
        );
    }

    private function __construct()
    {
    }
}
