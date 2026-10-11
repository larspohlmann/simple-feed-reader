<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Service\Fetch\Support\HostKey;
use Dom\Element;

/** Whether a link's text reads as a URL rather than as words, and whether that URL is the link's own page. */
final class UrlLinkText
{
    private const string URL = '#^(?:https?://|(?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[/:?\#]|$))#u';
    private const string SCHEME = '#^https?://#';
    private const string TRUNCATION = '/…$/u';

    public static function readsAsUrl(Element $anchor): bool
    {
        return preg_match(self::URL, self::visibleText($anchor)) === 1;
    }

    /** readsAsUrl(), held to a scheme or the link's own host, so text naming another site shares nothing. */
    public static function readsAsItsUrl(Element $anchor, string $href): bool
    {
        $text = self::visibleText($anchor);
        if (preg_match(self::URL, $text) !== 1) {
            return false;
        }
        $withoutWww = (string) preg_replace('/^www\./', '', $text);

        return preg_match(self::SCHEME, $text) === 1
            || preg_match('#^' . preg_quote(HostKey::forUrl($href), '#') . '(?:[/:?\#]|$)#', $withoutWww) === 1;
    }

    private static function visibleText(Element $anchor): string
    {
        $text = mb_strtolower((string) preg_replace('/^\s+|\s+$/u', '', $anchor->textContent ?? ''));

        return (string) preg_replace(self::TRUNCATION, '', $text);
    }

    private function __construct()
    {
    }
}
