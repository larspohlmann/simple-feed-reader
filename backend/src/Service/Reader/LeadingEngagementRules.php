<?php

declare(strict_types=1);

namespace App\Service\Reader;

final class LeadingEngagementRules
{
    public const int PROSE_CHARS = 120;

    private const float LINK_DOMINATED = 0.8;

    private const array COUNTER_NOUNS = [
        'klicks', 'aufrufe', 'reaktionen', 'kommentare', 'likes', 'shares',
        'clicks', 'views', 'reactions', 'comments',
    ];

    public static function isProse(string $text, int $linkTextLength): bool
    {
        $textLength = mb_strlen(self::collapsed($text));

        return $textLength >= self::PROSE_CHARS && $linkTextLength / $textLength < self::LINK_DOMINATED;
    }

    public static function isEmojiOnly(string $text): bool
    {
        $symbols = str_replace(["\u{FE0E}", "\u{FE0F}"], '', self::withoutWhitespace($text));

        $emojiSequence = '/^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\p{Regional_Indicator}'
            . '|\x{200D}|\x{20E3})+$/u';

        return $symbols !== '' && preg_match($emojiSequence, $symbols) === 1;
    }

    public static function isCounter(string $text): bool
    {
        $number = '(?:\\d{1,3}(?:[., ]\\d{3})*|\\d+)';
        $nouns = implode('|', self::COUNTER_NOUNS);

        return preg_match('/^' . $number . '\\s+(?:' . $nouns . ')$/u', mb_strtolower(self::collapsed($text))) === 1;
    }

    public static function isByline(string $text): bool
    {
        return preg_match('/^(?:von|by)\\s+\\S.*$/ui', self::collapsed($text)) === 1;
    }

    public static function hasAuthor(?string $entryAuthor): bool
    {
        return $entryAuthor !== null && trim($entryAuthor) !== '';
    }

    private static function withoutWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    private static function collapsed(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
