<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ValidationException;

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

    /**
     * The cursor a request asked for: absent when the client sent none, and a
     * 422 when it sent one we cannot read.
     *
     * `decode()` answers null for both cases, so every caller had to guard the
     * empty string first and then decide what a null meant. Two callers did,
     * with the same logic and the same message written twice — which put the
     * API's malformed-cursor contract in two hands.
     *
     * @throws ValidationException when the cursor is present but unreadable
     */
    public static function fromRequestValue(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::decode($raw)
            ?? throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
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
