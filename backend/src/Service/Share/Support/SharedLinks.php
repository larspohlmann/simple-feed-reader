<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Entity\LinkCardContent;
use App\Service\Fetch\Support\HostKey;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\TextNodes;
use App\Service\Share\Model\SharedLinksModel;
use App\Service\Text\Support\LinkedPlainText;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;

/** The links a post shares, a quoted post's included, as opposed to the hashtags, mentions and words that merely link. */
final class SharedLinks
{
    public const int MAX = 5;

    private const string BARE_URL_SPLIT = '#(' . LinkedPlainText::URL . ')#iu';
    private const string TRUNCATION = '…';

    public static function of(string $bodyHtml): SharedLinksModel
    {
        if (stripos($bodyHtml, 'http') === false) {
            return new SharedLinksModel([], $bodyHtml);
        }

        $document = HtmlDocumentParser::parseFragment($bodyHtml);
        $linkedHtml = self::linkBareUrls($document) === 0 ? $bodyHtml : ($document->body->innerHTML ?? $bodyHtml);

        return new SharedLinksModel(self::sharedUrlsIn($document), $linkedHtml);
    }

    /** @return list<string> */
    private static function sharedUrlsIn(HTMLDocument $document): array
    {
        $urls = [];
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            if (self::isShare($anchor)) {
                $urls[] = (string) $anchor->getAttribute('href');
            }
        }

        return \array_slice(array_values(array_unique($urls)), 0, self::MAX);
    }

    private static function linkBareUrls(HTMLDocument $document): int
    {
        $linked = 0;
        foreach (TextNodes::in($document->body) as $text) {
            if ($text->parentElement?->closest('a, code, pre') === null) {
                $linked += self::linkUrlsIn($text, $document);
            }
        }

        return $linked;
    }

    private static function linkUrlsIn(Text $text, HTMLDocument $document): int
    {
        $pieces = preg_split(self::BARE_URL_SPLIT, $text->data, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false || \count($pieces) < 2) {
            return 0;
        }

        $nodes = [];
        foreach ($pieces as $index => $piece) {
            $nodes[] = $index % 2 === 1 ? self::anchor($document, $piece) : $document->createTextNode($piece);
        }
        $text->replaceWith(...$nodes);

        return intdiv(\count($pieces), 2);
    }

    private static function anchor(HTMLDocument $document, string $url): Element
    {
        $anchor = $document->createElement('a');
        $anchor->setAttribute('href', $url);
        $anchor->textContent = $url;

        return $anchor;
    }

    private static function isShare(Element $anchor): bool
    {
        $href = (string) $anchor->getAttribute('href');

        return mb_strlen($href) <= LinkCardContent::URL_MAX
            && AbsoluteHttpUrl::matches($href)
            && self::readsAsItsUrl($anchor, $href);
    }

    private static function readsAsItsUrl(Element $anchor, string $href): bool
    {
        $visible = strtolower((string) preg_replace('/\s+/u', '', $anchor->textContent ?? ''));
        $visible = (string) preg_replace('/' . self::TRUNCATION . '$/u', '', $visible);
        $withoutWww = (string) preg_replace('/^www\./', '', $visible);

        return str_starts_with($visible, 'http://')
            || str_starts_with($visible, 'https://')
            || preg_match('#^' . preg_quote(HostKey::forUrl($href), '#') . '(?:[/:?\#]|$)#', $withoutWww) === 1;
    }

    private function __construct()
    {
    }
}
