<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * Page chrome: a sidebar, a navigation bar and a footer by HTML's own
 * sectioning vocabulary, plus a related-content teaser named by class. Neither
 * holds the article's media. Measured across twelve publishers (#748): no
 * article kept its own player under a section element, and every stray candidate
 * sat under one. `header` is deliberately absent — an article's hero commonly
 * sits in its own header.
 *
 * NDR marks a "Mehr zum Thema" teaser as a plain `<div class="teaser">`, not a
 * section element, so its inline-play clip leaked in as article media (#1058).
 * A `teaser`/`related` class token is the signal, matched at a token boundary so
 * a Tailwind utility (`group/teaser`) never counts; `promo` is deliberately
 * absent — NPR's own audio plays from a `promo-module`.
 */
final readonly class PageFurniture
{
    private const string CHROME =
        'aside, nav, footer, [class^="teaser"], [class*=" teaser"], [class^="related"], [class*=" related"]';

    public static function holds(Element $element): bool
    {
        return $element->closest(self::CHROME) !== null;
    }
}
