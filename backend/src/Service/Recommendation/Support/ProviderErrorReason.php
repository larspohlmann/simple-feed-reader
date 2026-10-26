<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Support;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Model\ProviderErrorIdentifiersModel;
use App\Service\Text\Support\ClippedText;

/**
 * What a provider objected to: TypeSafe's `detail` or OpenAI's and OpenRouter's `error.message`. Never the raw body,
 * which on OpenRouter carries the account's `user_id`.
 */
final class ProviderErrorReason
{
    private const int CHARACTERS = 500;

    private const int IDENTIFIER_CHARACTERS = 64;

    /** Redacted before it is clipped, so a key that straddles the clip leaves no prefix behind. */
    public static function in(string $body, ProviderCredentialsModel $credentials): ?string
    {
        $reason = self::reasonIn($body);

        return null === $reason
            ? null
            : ClippedText::ofScrubbed($credentials->withoutApiKey($reason), self::CHARACTERS);
    }

    /** Short identifiers, never part of a message, so clipped without the reason's redaction. */
    public static function identifiersIn(string $body): ProviderErrorIdentifiersModel
    {
        $error = self::rootOf($body)['error'] ?? null;
        $fields = \is_array($error) ? $error : [];

        return new ProviderErrorIdentifiersModel(
            self::identifier($fields['code'] ?? null),
            self::identifier($fields['param'] ?? null),
        );
    }

    private static function reasonIn(string $body): ?string
    {
        $root = self::rootOf($body);
        $error = $root['error'] ?? null;
        $reason = $root['detail'] ?? (\is_array($error) ? $error['message'] ?? null : null);

        return match (true) {
            \is_string($reason) => $reason,
            \is_array($reason) => CompactJson::encode($reason),
            default => null,
        };
    }

    /** @return array<mixed> */
    private static function rootOf(string $body): array
    {
        $root = json_decode($body, true, flags: \JSON_INVALID_UTF8_SUBSTITUTE);

        return \is_array($root) ? $root : [];
    }

    private static function identifier(mixed $value): ?string
    {
        return \is_string($value) ? mb_substr($value, 0, self::IDENTIFIER_CHARACTERS) : null;
    }

    private function __construct()
    {
    }
}
