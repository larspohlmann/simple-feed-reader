<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Dom\Element;

/**
 * Page chrome by HTML's own sectioning vocabulary: a sidebar, a navigation
 * bar and a footer hold related-content teasers and station streams, never
 * the article's media. Measured across twelve publishers (#748): no article
 * kept its own player under one of these, and every stray candidate sat under
 * one. `header` is deliberately absent — an article's hero commonly sits in
 * its own header.
 */
final readonly class PageFurniture
{
    private const string CHROME = 'aside, nav, footer';

    public static function holds(Element $element): bool
    {
        return $element->closest(self::CHROME) !== null;
    }
}
