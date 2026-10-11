<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Service\Fetch\Support\HostKey;
use App\Service\Text\Support\Whitespace;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;

/** Whether a link's text reads as a URL rather than as words, and whether that URL is the link's own page. */
final class UrlLinkText
{
    private const string ANY_HOST = '[a-z0-9-]+(?:\.[a-z0-9-]+)+';
    private const string TRUNCATION = '/…$/u';

    public static function readsAsUrl(Element $anchor): bool
    {
        $text = self::visibleText($anchor);

        return AbsoluteHttpUrl::matches($text) || self::startsWithHost($text, self::ANY_HOST);
    }

    /** readsAsUrl() held to the link's own host, so text naming another site shares nothing. */
    public static function readsAsItsUrl(Element $anchor, string $href): bool
    {
        $text = self::visibleText($anchor);

        return AbsoluteHttpUrl::matches($text) || self::startsWithHost($text, preg_quote(HostKey::forUrl($href), '#'));
    }

    private static function startsWithHost(string $text, string $hostPattern): bool
    {
        return preg_match('#^(?:www\.)?' . $hostPattern . '(?:[/:?\#]|$)#u', $text) === 1;
    }

    private static function visibleText(Element $anchor): string
    {
        return (string) preg_replace(self::TRUNCATION, '', strtolower(Whitespace::collapse($anchor->textContent)));
    }

    private function __construct()
    {
    }
}
