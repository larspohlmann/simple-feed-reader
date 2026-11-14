<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * Page chrome: aside, nav and footer (across twelve publishers no article player sat under one, #748), plus a
 * `teaser`/`related` class token. `header` stays out because it holds the hero, and so does `promo`: a publisher's
 * own audio can play from a `promo-module`.
 */
final readonly class PageFurniture
{
    private const string CHROME =
        'aside, nav, footer, [class^="teaser"], [class*=" teaser"], [class^="related"], [class*=" related"]';

    public function holds(Element $element): bool
    {
        return $element->closest(self::CHROME) !== null;
    }
}
