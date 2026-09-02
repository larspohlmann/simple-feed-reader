<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

/**
 * Text with every whitespace removed. The page text, a paywall block and the
 * cleaned body are compared as substrings and by position; the source's
 * indentation and the serializer's line breaks must never decide a match.
 */
final readonly class SqueezedText
{
    private const string WHITESPACE = '/[\s\x{00A0}]+/u';

    public static function of(string $text): string
    {
        return (string) preg_replace(self::WHITESPACE, '', $text);
    }
}
