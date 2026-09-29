<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Exception\ValidationException;
use App\Pagination\Exception\MalformedCursorException;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Opaque keyset cursor for the entry list: base64url of "<sortInstant ISO8601>|<id>", ours to change. `sortInstant`
 * is the instant the list orders by (EntryListSort); `id` breaks its many ties, as a refresh run shares one instant.
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
     * An upper bound that admits every row AT $until, not only those before it: the keyset predicate is strict, so
     * the max int id stands in for the id. Never encoded for a client.
     */
    public static function inclusiveUpperBound(\DateTimeImmutable $until): self
    {
        return new self($until, PHP_INT_MAX);
    }

    public static function encode(\DateTimeImmutable $sortInstant, int $id): string
    {
        $raw = $sortInstant->format(\DateTimeInterface::ATOM) . '|' . $id;

        return Base64UrlSafe::encodeUnpadded($raw);
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
