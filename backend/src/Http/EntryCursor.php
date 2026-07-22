<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Opaque keyset-pagination cursor for the entry list: base64url of
 * "<effectiveDate ISO8601>|<id>". The client treats it as a token; the format
 * is ours to change. `date` is the entry's publishedAt ?? createdAt — the same
 * value the list orders by — and `id` is the tie-breaker for equal timestamps.
 */
final readonly class EntryCursor
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $id,
    ) {
    }

    public static function encode(\DateTimeImmutable $date, int $id): string
    {
        $raw = $date->format(\DateTimeInterface::ATOM) . '|' . $id;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): ?self
    {
        if ($cursor === '') {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }

        $parts = explode('|', $raw);
        if (\count($parts) !== 2 || !ctype_digit($parts[1])) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if ($date === false) {
            return null;
        }

        return new self($date, (int) $parts[1]);
    }
}
