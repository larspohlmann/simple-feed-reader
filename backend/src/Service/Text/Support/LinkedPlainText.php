<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/** Text known to carry no markup, as HTML: escaped, paragraphed, its bare URLs linked. */
final class LinkedPlainText
{
    private const string TEXT_THEN_URL_PATTERN = '#(.*?)(?:(https?://[^\s<>"]+?)(?=[.,;:!?)\]]*(?:\s|$))|$)#is';

    public static function asHtml(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        return ParagraphedText::asHtml($text, self::linked(...));
    }

    private static function linked(string $line): string
    {
        return preg_replace_callback(
            self::TEXT_THEN_URL_PATTERN,
            static fn (array $match): string => HtmlEscape::text($match[1]) . self::link($match[2] ?? ''),
            $line,
        ) ?? HtmlEscape::text($line);
    }

    private static function link(string $url): string
    {
        return $url === '' ? '' : '<a href="' . HtmlEscape::text($url) . '">' . HtmlEscape::text($url) . '</a>';
    }

    private function __construct()
    {
    }
}
