<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Model\TextSpanModel;

/** Finds the links, hashtags and mentions in a post's text, the way Bluesky's own clients detect them. */
final class BlueskyTextSpans
{
    private const string LINK = '~(?:^|\s|\()(https?://\S+)~u';
    private const string TAG = '~(?:^|\s)[#＃]([^\s#＃]*[^\d\s\p{P}#＃][^\s#＃]*)~u';
    private const string MENTION = '~(?:^|\s|\()@([a-zA-Z0-9.-]+\.[a-zA-Z][a-zA-Z0-9-]*)\b~u';
    private const string TRAILING_PUNCTUATION = '~\p{P}+$~u';
    private const string TRAILING_LINK_PUNCTUATION = '~[.,;:!?"\']+$~';
    private const int MAX_TAG_LENGTH = 64;

    private function __construct()
    {
    }

    /** @return list<TextSpanModel> */
    public static function links(string $text): array
    {
        $spans = [];
        foreach (self::matches(self::LINK, $text) as [$link, $start]) {
            $trimmed = self::withoutUnbalancedParenthesis(
                (string) preg_replace(self::TRAILING_LINK_PUNCTUATION, '', $link),
            );
            $spans[] = new TextSpanModel($start, $start + \strlen($trimmed), $trimmed);
        }

        return $spans;
    }

    /** @return list<TextSpanModel> */
    public static function tags(string $text): array
    {
        $spans = [];
        foreach (self::matches(self::TAG, $text) as [$tag, $start]) {
            $trimmed = (string) preg_replace(self::TRAILING_PUNCTUATION, '', $tag);
            if ('' === $trimmed || mb_strlen($trimmed) > self::MAX_TAG_LENGTH) {
                continue;
            }
            $hashStart = $start - \strlen(self::hashBefore($text, $start));
            $spans[] = new TextSpanModel($hashStart, $start + \strlen($trimmed), $trimmed);
        }

        return $spans;
    }

    /** @return list<TextSpanModel> */
    public static function mentions(string $text): array
    {
        $spans = [];
        foreach (self::matches(self::MENTION, $text) as [$handle, $start]) {
            $spans[] = new TextSpanModel($start - 1, $start + \strlen($handle), mb_strtolower($handle));
        }

        return $spans;
    }

    /** @return list<array{string, int}> the first group of each match and its byte offset */
    private static function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $found, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[1][0], $match[1][1]], $found);
    }

    private static function hashBefore(string $text, int $start): string
    {
        return str_ends_with(substr($text, 0, $start), '＃') ? '＃' : '#';
    }

    private static function withoutUnbalancedParenthesis(string $link): string
    {
        $opened = substr_count($link, '(');
        $closed = substr_count($link, ')');

        return str_ends_with($link, ')') && $closed > $opened ? substr($link, 0, -1) : $link;
    }
}
