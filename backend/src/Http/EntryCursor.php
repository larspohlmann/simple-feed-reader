<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Opaque keyset-pagination cursor for the entry list: base64url of
 * "<effectiveDate ISO8601>|<id>". The client treats it as a token; the format
 * is ours to change.
 *
 * `effectiveDate` is the entry's list-sort instant (see EntryEffectiveDate);
 * `id` breaks the ties it leaves, and there are many — a whole refresh run
 * shares one effective date.
 */
final readonly class EntryCursor
{
    public function __construct(
        public \DateTimeImmutable $effectiveDate,
        public int $id,
    ) {
    }

    public static function encode(\DateTimeImmutable $effectiveDate, int $id): string
    {
        $raw = $effectiveDate->format(\DateTimeInterface::ATOM) . '|' . $id;

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

        $effectiveDate = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if ($effectiveDate === false) {
            return null;
        }

        return new self($effectiveDate, (int) $parts[1]);
    }
}
