<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Opaque keyset-pagination cursor for the for-you feed: base64url of
 * "<runId>|<position>". Modeled on EntryCursor, but the for-you feed orders by
 * (run DESC, position ASC) — a score order the (effectiveDate, id) cursor
 * cannot express — so it needs its own pair.
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
        if (\count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        return new self((int) $parts[0], (int) $parts[1]);
    }
}
