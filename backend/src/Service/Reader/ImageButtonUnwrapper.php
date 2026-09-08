<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Promotes a content photo out of a <button> wrapper. Some sites (bild.de #955)
 * wrap each article image in a lightbox-trigger button; readability drops every
 * <button> with its whole subtree, so the photo vanishes and its sibling
 * <figcaption> is left orphaned. Unwrapping the button first keeps the photo in
 * place.
 *
 * Only a real content photo is rescued, never a genuine image-button — a
 * control (play, share, menu) whose face is an icon. The two are told apart by
 * size: an icon and a tracking beacon are small, an article photo is not. So a
 * button is unwrapped only for an image whose declared dimensions clear the
 * icon ceiling; anything smaller, undimensioned, or without a real source is
 * left for readability to discard as chrome.
 *
 * Runs after LazyImageSources, which has already resolved every lazy `src` and
 * removed images with no usable source, so a surviving button image is loadable.
 */
final readonly class ImageButtonUnwrapper
{
    /** An edge at or below this is an icon or a beacon; an article photo clears it. */
    private const int ICON_EDGE_CEILING = 100;

    public function unwrapIn(HTMLDocument $document): void
    {
        // A snapshot, since replaceWith mutates the live tag list mid-walk. A
        // button cannot nest inside a button, so document order needs no reversal.
        foreach (iterator_to_array($document->getElementsByTagName('button')) as $button) {
            if ($button->parentNode !== null && $this->wrapsContentPhoto($button)) {
                $button->replaceWith(...iterator_to_array($button->childNodes));
            }
        }
    }

    private function wrapsContentPhoto(Element $button): bool
    {
        foreach ($button->getElementsByTagName('img') as $image) {
            if ($this->isContentPhoto($image)) {
                return true;
            }
        }

        return false;
    }

    private function isContentPhoto(Element $image): bool
    {
        $source = trim($image->getAttribute('src') ?? '');
        if (preg_match('#^https?://#i', $source) !== 1) {
            return false;
        }

        return (int) $image->getAttribute('width') > self::ICON_EDGE_CEILING
            && (int) $image->getAttribute('height') > self::ICON_EDGE_CEILING;
    }
}
