<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

/** Mastodon's StatusLengthValidator, which the composer's `post-length.ts` mirrors. */
final class MastodonTextLength
{
    public const int LINK_LENGTH = 23;
    /** PHP's `u` turns on UCP, so JavaScript's `\s` and its ASCII `\w` are spelled out. */
    private const string SPACE
        = '\x09-\x0D\x20\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    private const string WORD = 'A-Za-z0-9_';
    private const string LINK = '~https?://[^' . self::SPACE . ']+~u';
    private const string REMOTE_MENTION
        = '~(^|[^' . self::WORD . '/])@([' . self::WORD . ']+)@[' . self::WORD . '.-]*[' . self::WORD . ']~u';

    private function __construct()
    {
    }

    public static function measureLength(string $contentWarning, string $text): int
    {
        $withoutLinks = preg_replace(self::LINK, '', $text, -1, $links) ?? $text;
        $withoutHosts = preg_replace(self::REMOTE_MENTION, '$1@$2', $withoutLinks) ?? $withoutLinks;
        $countable = $contentWarning . $withoutHosts;
        $graphemes = grapheme_strlen($countable);

        return (\is_int($graphemes) ? $graphemes : mb_strlen($countable)) + $links * self::LINK_LENGTH;
    }
}
