<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Support\ClippedText;

/**
 * A refused request's failure, naming what the provider objected to: TypeSafe's `detail` or OpenRouter's
 * `error.message`. Never the raw body, which on OpenRouter carries the account's `user_id`.
 */
final class RefusalMessage
{
    private const int DETAIL_CHARACTERS = 500;

    public static function of(int $status, string $body): string
    {
        $detail = self::detailIn(json_decode($body, true, flags: \JSON_INVALID_UTF8_SUBSTITUTE));
        if (null === $detail) {
            return sprintf('That provider refused the request (status %d).', $status);
        }

        return sprintf(
            'That provider refused the request (status %d): %s',
            $status,
            ClippedText::ofScrubbed($detail, self::DETAIL_CHARACTERS),
        );
    }

    private static function detailIn(mixed $root): ?string
    {
        if (!\is_array($root)) {
            return null;
        }
        $error = $root['error'] ?? null;
        $detail = $root['detail'] ?? (\is_array($error) ? $error['message'] ?? null : null);

        return match (true) {
            \is_string($detail) => $detail,
            \is_array($detail) => SystemOneJson::encode($detail),
            default => null,
        };
    }

    private function __construct()
    {
    }
}
