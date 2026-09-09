<?php

declare(strict_types=1);

namespace App\Service\Html;

use Dom\Element;

/**
 * Reads a <picture>'s <source> set, the responsive renditions a browser would
 * choose from. The reader shows one rendition in a desktop column, so it needs
 * either the widest usable source (to beat a placeholder <img>) or the first
 * usable one (to give a src-less <img> a source at all).
 *
 * The candidates sit on the same lazy attributes an <img> is read by, so a lazy
 * <picture> keeps them on `data-srcset` (nature.com, #789) and the reader still
 * finds them.
 */
final readonly class PictureSources
{
    /** Attributes holding a candidate list, in the order publishers prefer them. */
    private const array SRCSET_ATTRIBUTES = ['data-lazy-srcset', 'data-srcset', 'srcset'];

    /** A URL carrying a scheme that is neither http nor https — never a candidate. */
    private const string FOREIGN_SCHEME = '#^(?!https?://)[a-z][a-z0-9+.\-]*:#i';

    /**
     * The widest usable rendition the <source> set offers. When no source
     * declares a width the first usable one stands in, so a src-less picture
     * still resolves to a real image.
     */
    public function widest(Element $picture): ?ImageRendition
    {
        $widest = null;
        foreach ($picture->getElementsByTagName('source') as $source) {
            $rendition = $this->renditionOf($source);
            if ($rendition !== null && ($widest === null || $rendition->outsizes($widest))) {
                $widest = $rendition;
            }
        }

        return $widest;
    }

    /** The first usable candidate URL across the <source> set, or null. */
    public function firstUsableUrl(Element $picture): ?string
    {
        foreach ($picture->getElementsByTagName('source') as $source) {
            $candidate = Srcset::firstUrl($this->srcsetOf($source));
            if ($candidate !== null && $this->isUsable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** A source's widest usable candidate, its width filled from the URL when the
     *  srcset omits a descriptor. Null when the source is scoped to a viewport
     *  the reader does not stand in for, or carries nothing loadable. */
    private function renditionOf(Element $source): ?ImageRendition
    {
        if (!DesktopViewport::admits($source->getAttribute('media'))) {
            return null;
        }

        $candidate = Srcset::widest($this->srcsetOf($source));
        if ($candidate === null || !$this->isUsable($candidate->url)) {
            return null;
        }

        return new ImageRendition($candidate->url, $candidate->width ?? ImageRendition::widthFromUrl($candidate->url));
    }

    /** A <source>'s candidate list, from the same lazy attributes an <img> is read by. */
    private function srcsetOf(Element $source): ?string
    {
        foreach (self::SRCSET_ATTRIBUTES as $attribute) {
            $srcset = $source->getAttribute($attribute);
            if ($srcset !== null && trim($srcset) !== '') {
                return $srcset;
            }
        }

        return null;
    }

    /** A relative URL stays usable: readability resolves it against the page URL. */
    private function isUsable(string $url): bool
    {
        return $url !== '' && preg_match(self::FOREIGN_SCHEME, $url) !== 1;
    }
}
