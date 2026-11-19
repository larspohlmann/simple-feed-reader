<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Service\Foundation\Text\Support\EntryPlainText;
use App\Service\Foundation\Text\Support\WordBoundary;

final class HeadlineBody
{
    /** What a query reads of a body before its markup is stripped and BODY_LENGTH cuts it. */
    public const int SOURCE_CHARACTERS = 2_000;

    private const int BODY_LENGTH = 300;
    private const int EXCERPT_LENGTH = 1_000;
    private const string TRAILING_ELLIPSIS = '/…$/u';

    public static function body(?string $summary): string
    {
        return EntryPlainText::cut($summary, self::BODY_LENGTH);
    }

    public static function bodyAfterTitle(string $title, ?string $summary): string
    {
        return WordBoundary::cut(self::textAfterTitle($title, $summary), self::BODY_LENGTH);
    }

    public static function excerpt(?string $summary): string
    {
        return WordBoundary::cut(EntryPlainText::of($summary) ?? '', self::EXCERPT_LENGTH);
    }

    public static function excerptAfterTitle(string $title, ?string $summary): string
    {
        return WordBoundary::cut(self::textAfterTitle($title, $summary), self::EXCERPT_LENGTH);
    }

    private static function textAfterTitle(string $title, ?string $summary): string
    {
        $text = EntryPlainText::of($summary) ?? '';
        $head = trim(preg_replace(self::TRAILING_ELLIPSIS, '', $title) ?? $title);
        if ($head === '' || !str_starts_with($text, $head)) {
            return $text;
        }

        return ltrim(mb_substr($text, mb_strlen($head)));
    }

    private function __construct()
    {
    }
}
