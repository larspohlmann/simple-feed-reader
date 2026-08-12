<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Opaque keyset-pagination cursor for the entry list: base64url of
 * "<createdAt ISO8601>|<publishedAt ISO8601 or empty>|<id>". The client treats
 * it as a token; the format is ours to change.
 *
 * `createdAt` is the entry's refresh-run-start timestamp (the new list sort's
 * primary key); `publishedAt` is the feed-supplied publication date or null
 * (within-run tiebreaker, null sorts last); `id` is the final tiebreaker.
 */
final readonly class EntryCursor
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $publishedAt,
        public int $id,
    ) {
    }

    public static function encode(\DateTimeImmutable $createdAt, ?\DateTimeImmutable $publishedAt, int $id): string
    {
        $raw = $createdAt->format(\DateTimeInterface::ATOM)
            . '|' . ($publishedAt === null ? '' : $publishedAt->format(\DateTimeInterface::ATOM))
            . '|' . $id;

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
        if (\count($parts) !== 3 || !ctype_digit($parts[2])) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[0]);
        if ($createdAt === false) {
            return null;
        }

        $publishedAt = null;
        if ($parts[1] !== '') {
            $publishedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $parts[1]);
            if ($publishedAt === false) {
                return null;
            }
        }

        return new self($createdAt, $publishedAt, (int) $parts[2]);
    }
}
