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
     * @param array<string, mixed> $line
     */
    public static function string(array $line, string $key, string $default): string
    {
        if (!\array_key_exists($key, $line)) {
            return $default;
        }

        return LineField::string($line, $key);
    }
}
