<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * Reads a field that a pre-existing backup may not carry. A file written
 * before the key existed omits it, and must import as the given default
 * rather than being rejected as malformed.
 */
final class LineFieldWithDefault
{
    /**
     * @param array<string, mixed> $line
     */
    public static function bool(array $line, string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $line)) {
            return $default;
        }

        return LineField::bool($line, $key);
    }

    /**
     * An unreadable value imports as the default too: a style is presentation,
     * so a backup is worth more restored than rejected over one stale word.
     *
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $line
     * @param T                    $default
     *
     * @return T
     */
    public static function enum(array $line, string $key, \BackedEnum $default): \BackedEnum
    {
        if (!\array_key_exists($key, $line)) {
            return $default;
        }

        return $default::tryFrom(LineField::string($line, $key)) ?? $default;
    }
}
