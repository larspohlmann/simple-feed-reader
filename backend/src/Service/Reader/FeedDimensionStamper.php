<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Stamps the feed-declared pixel size onto a reader image or video whose URL the
 * feed enumerated (#914). The reader otherwise emits no dimensions at all — the
 * rendition machinery spends the declared width only to pick the sharpest URL,
 * then discards it — so every picture reflows the article as it loads. The feed
 * already stated the real size for exactly these assets; stamping it once, over
 * the assembled body, gives the browser the box to reserve. A picture the reader
 * already sized (readability carried its width) is left untouched.
 */
final class FeedDimensionStamper
{
    public static function stampInto(HTMLDocument $document, FeedMedia $feedMedia): void
    {
        foreach ($document->querySelectorAll('img[src], video[src]') as $element) {
            self::stamp($element, $feedMedia);
        }
    }

    private static function stamp(Element $element, FeedMedia $feedMedia): void
    {
        if ($element->hasAttribute('width') || $element->hasAttribute('height')) {
            return;
        }
        $medium = $feedMedia->declaredMediumFor($element->getAttribute('src') ?? '');
        if ($medium === null || $medium->width === null || $medium->height === null) {
            return;
        }

        $element->setAttribute('width', (string) $medium->width);
        $element->setAttribute('height', (string) $medium->height);
    }
}
