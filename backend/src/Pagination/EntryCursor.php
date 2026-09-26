<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Exception\ValidationException;
use App\Pagination\Exception\MalformedCursorException;

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

    /** @throws ValidationException when the cursor is present but unreadable */
    public static function fromRequestValue(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return self::decode($raw);
        } catch (MalformedCursorException) {
            throw new ValidationException(['cursor' => ['The cursor is malformed.']]);
        }
    }

    /**
     * The upper bound of a keyset walk that must include every row AT $until, not
     * only those strictly before it. The keyset predicate is strict (id < c.id),
     * so the max int id admits every real (auto-increment) id at $until. Internal
     * to the engine mark-read enumeration; never encoded for a client.
     */
    public static function inclusiveUpperBound(\DateTimeImmutable $until): self
    {
        return new self($until, PHP_INT_MAX);
    }

    public static function encode(\DateTimeImmutable $sortInstant, int $id): string
    {
        $raw = $sortInstant->format(\DateTimeInterface::ATOM) . '|' . $id;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @throws MalformedCursorException */
    public static function decode(string $cursor): self
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = false === $raw ? [] : explode('|', $raw);
        if (\count($parts) !== 2 || !ctype_digit($parts[1])) {
            throw new MalformedCursorException();
        }

        $sortInstant = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if (false === $sortInstant) {
            throw new MalformedCursorException();
        }

        return new self($sortInstant, (int) $parts[1]);
    }
}
