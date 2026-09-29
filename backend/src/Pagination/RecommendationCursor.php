<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Pagination\Exception\MalformedCursorException;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Opaque keyset cursor for the for-you feed: base64url of "<runId>|<position>". Its own pair, because the feed
 * orders by (run DESC, position ASC), which EntryCursor's (instant, id) cannot express.
 */
final readonly class RecommendationCursor
{
    public function __construct(
        public int $runId,
        public int $position,
    ) {
    }

    public static function encode(int $runId, int $position): string
    {
        $raw = $runId . '|' . $position;

        return Base64UrlSafe::encodeUnpadded($raw);
    }

    /** @throws MalformedCursorException */
    public static function decode(string $cursor): self
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = false === $raw ? [] : explode('|', $raw);
        if (\count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            throw new MalformedCursorException();
        }

        return new self((int) $parts[0], (int) $parts[1]);
    }
}
