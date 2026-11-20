<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Support;

use App\Entity\LinkCardContent;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Foundation\Html\Support\TextNodes;
use App\Service\Foundation\Text\Support\LinkedPlainText;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Social\Share\Model\SharedLinksModel;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;

/**
 * The links a post shares, a quoted post's included, as opposed to the hashtags, mentions and words that merely link.
 * Each is tracking-free, the form its card is stored under.
 */
final class SharedLinks
{
    public const int MAX = 5;

    private const string BARE_URL_SPLIT = '#(' . LinkedPlainText::URL . ')#iu';

    public static function fromBodyHtml(string $bodyHtml): SharedLinksModel
    {
        $document = HtmlDocumentParser::parseFragment($bodyHtml);
        $linkedHtml = self::linkBareUrls($document) === 0 ? $bodyHtml : ($document->body->innerHTML ?? $bodyHtml);

        $hrefsToCheck = self::collectSharedUrls($document);
        $urls = \array_slice(array_keys($hrefsToCheck), 0, self::MAX);

        return new SharedLinksModel(
            $urls,
            $linkedHtml,
            OwnProse::fromBody($document->body),
            array_filter(array_intersect_key($hrefsToCheck, array_flip($urls))),
        );
    }

    /**
     * @return array<string, ?string> each shared link's first posted href, when it lost an undocumented key, by its
     *                                tracking-free URL, in post order
     */
    private static function collectSharedUrls(HTMLDocument $document): array
    {
        $hrefsToCheck = [];
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            if (!self::isShare($anchor)) {
                continue;
            }
            $href = (string) $anchor->getAttribute('href');
            $read = TrackingFreeUrl::read($href);
            $url = $read->cleaned();
            if (!\array_key_exists($url, $hrefsToCheck)) {
                $hrefsToCheck[$url] = $read->strippedAnUndocumentedKey() ? $href : null;
            }
        }

        return $hrefsToCheck;
    }

    private static function linkBareUrls(HTMLDocument $document): int
    {
        $linked = 0;
        foreach (TextNodes::in($document->body) as $text) {
            if ($text->parentElement?->closest('a, code, pre') === null) {
                $linked += self::linkBareUrlsInText($text, $document);
            }
        }

        return $linked;
    }

    private static function linkBareUrlsInText(Text $text, HTMLDocument $document): int
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
            && UrlLinkText::readsAsItsUrl($anchor, $href);
    }

    private function __construct()
    {
    }
}
