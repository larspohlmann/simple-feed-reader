<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

/**
 * What a provider objected to: TypeSafe's `detail` or OpenAI's and OpenRouter's `error.message`. Never the raw body,
 * which on OpenRouter carries the account's `user_id`.
 */
final class ProviderErrorReason
{
    private const int CHARACTERS = 500;

    public static function in(string $body): ?string
    {
        $root = json_decode($body, true, flags: \JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_array($root)) {
            return null;
        }
        $error = $root['error'] ?? null;
        $reason = $root['detail'] ?? (\is_array($error) ? $error['message'] ?? null : null);

        return match (true) {
            \is_string($reason) => ClippedText::ofScrubbed($reason, self::CHARACTERS),
            \is_array($reason) => ClippedText::ofScrubbed(self::compactJson($reason), self::CHARACTERS),
            default => null,
        };
    }

    /** @param array<mixed> $structure */
    private static function compactJson(array $structure): string
    {
        return json_encode($structure, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private function __construct()
    {
    }
}
