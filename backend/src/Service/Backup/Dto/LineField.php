<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Typed accessors for a decoded backup line. Each helper reads one key from
 * the line's associative array and throws InvalidBackupException naming that
 * key when the value is missing or of the wrong shape — so a malformed
 * backup fails with a message an operator can act on, never a silent
 * type-juggled default.
 */
final class LineField
{
    /**
     * @param array<string, mixed> $line
     */
    public static function string(array $line, string $key): string
    {
        $value = $line[$key] ?? null;
        if (!\is_string($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is missing or not a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function stringOrNull(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is not a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function int(array $line, string $key): int
    {
        $value = $line[$key] ?? null;
        if (!\is_int($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is missing or not an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function intOrNull(array $line, string $key): ?int
    {
        $value = $line[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (!\is_int($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is not an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function bool(array $line, string $key): bool
    {
        $value = $line[$key] ?? null;
        if (!\is_bool($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is missing or not a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function date(array $line, string $key): \DateTimeImmutable
    {
        $value = self::string($line, $key);
        if ('' === trim($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is not a valid date.', $key));
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\DateMalformedStringException) {
            throw new InvalidBackupException(sprintf('Field "%s" is not a valid date.', $key));
        }
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function dateOrNull(array $line, string $key): ?\DateTimeImmutable
    {
        $value = $line[$key] ?? null;
        if (null === $value) {
            return null;
        }

        return self::date($line, $key);
    }

    /**
     * Reads a nested JSON object field, e.g. `recommendationSettings`, whose
     * own keys a dto factory then reads with these same helpers.
     *
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>|null
     */
    public static function objectOrNull(array $line, string $key): ?array
    {
        $value = $line[$key] ?? null;
        if (null === $value) {
            return null;
        }

        return self::asObject($value, $key);
    }

    /**
     * Reads a nested JSON array-of-objects field, e.g. a subscription's
     * `tags`, as a list of decoded objects.
     *
     * @param array<string, mixed> $line
     *
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $line, string $key): array
    {
        $value = $line[$key] ?? null;
        if (!\is_array($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is missing or not an array.', $key));
        }

        return array_map(
            static fn (mixed $item): array => self::asObject($item, $key),
            array_values($value),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function asObject(mixed $value, string $key): array
    {
        if (!\is_array($value)) {
            throw new InvalidBackupException(sprintf('Field "%s" is not an object.', $key));
        }

        $object = [];
        foreach ($value as $nestedKey => $nestedValue) {
            if (!\is_string($nestedKey)) {
                throw new InvalidBackupException(sprintf('Field "%s" is not an object.', $key));
            }

            $object[$nestedKey] = $nestedValue;
        }

        return $object;
    }
}
