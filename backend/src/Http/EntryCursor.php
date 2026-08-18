<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ValidationException;

/**
 * Opaque keyset-pagination cursor for the entry list: base64url of
 * "<sortInstant ISO8601>|<id>". The client treats it as a token; the format
 * is ours to change.
 *
 * `sortInstant` is the row's position along whichever instant the list orders
 * by (see EntryListSort): the entry's publish instant for every date-ordered
 * list, the caller's view instant for the "viewed" history. `id` breaks the
 * ties it leaves, and there are many — a whole refresh run shares one instant.
 */
final readonly class EntryCursor
{
    public function __construct(
        public \DateTimeImmutable $sortInstant,
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

    public static function encode(\DateTimeImmutable $sortInstant, int $id): string
    {
        $raw = $sortInstant->format(\DateTimeInterface::ATOM) . '|' . $id;

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

        $sortInstant = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if ($sortInstant === false) {
            return null;
        }

        return new self($sortInstant, (int) $parts[1]);
    }
}
