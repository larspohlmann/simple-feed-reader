<?php

declare(strict_types=1);

namespace App\Service\Social\Share\Support;

use App\Entity\LinkCardContent;
use App\Service\Foundation\Html\Support\BareUrlLinks;
use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Social\Share\Model\SharedLinksModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * The links a post shares, a quoted post's included, as opposed to the hashtags, mentions and words that merely link.
 * Each is tracking-free, the form its card is stored under.
 */
final class SharedLinks
{
    public const int MAX = 5;

    public static function fromBodyHtml(string $bodyHtml): SharedLinksModel
    {
        $document = HtmlDocumentParser::parseFragment($bodyHtml);
        $linkedHtml = BareUrlLinks::linkIn($document, $bodyHtml);

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

    private static function isShare(Element $anchor): bool
    {
        $href = (string) $anchor->getAttribute('href');

        return mb_strlen($href) <= LinkCardContent::URL_MAX
            && AbsoluteHttpUrl::matches($href)
            && UrlLinkText::readsAsItsUrl($anchor, $href)
            && !CrossPostCredit::isCredit($anchor);
    }

    private function __construct()
    {
    }
}
