<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

use App\Service\Ai\Model\ProviderCredentialsModel;

/**
 * What a provider objected to: TypeSafe's `detail` or OpenAI's and OpenRouter's `error.message`. Never the raw body,
 * which on OpenRouter carries the account's `user_id`.
 */
final class ProviderErrorReason
{
    private const int CHARACTERS = 500;

    /** Redacted before it is clipped, so a key that straddles the clip leaves no prefix behind. */
    public static function in(string $body, ProviderCredentialsModel $credentials): ?string
    {
        $reason = self::reasonIn($body);

        return null === $reason
            ? null
            : ClippedText::ofScrubbed($credentials->withoutApiKey($reason), self::CHARACTERS);
    }

    private static function reasonIn(string $body): ?string
    {
        $root = json_decode($body, true, flags: \JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_array($root)) {
            return null;
        }
        $error = $root['error'] ?? null;
        $reason = $root['detail'] ?? (\is_array($error) ? $error['message'] ?? null : null);

        return match (true) {
            \is_string($reason) => $reason,
            \is_array($reason) => CompactJson::encode($reason),
            default => null,
        };
    }

    private function __construct()
    {
    }
}
