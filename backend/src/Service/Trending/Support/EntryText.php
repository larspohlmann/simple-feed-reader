<?php

declare(strict_types=1);

namespace App\Service\Trending\Support;

use App\Service\Text\Support\PlainText;

final class EntryText
{
    private const int BODY_LENGTH = 300;
    private const string TRAILING_ELLIPSIS = '/…$/u';

    public static function body(?string $summary): string
    {
        return self::cut(PlainText::fromHtmlBlocks($summary) ?? '');
    }

    public static function bodyAfterTitle(string $title, ?string $summary): string
    {
        $text = PlainText::fromHtmlBlocks($summary) ?? '';
        $head = trim(preg_replace(self::TRAILING_ELLIPSIS, '', $title) ?? $title);
        if ($head === '' || !str_starts_with($text, $head)) {
            return self::cut($text);
        }

        return self::cut(ltrim(mb_substr($text, mb_strlen($head))));
    }

    private static function cut(string $text): string
    {
        return mb_substr($text, 0, self::BODY_LENGTH);
    }

    private function __construct()
    {
    }
}
