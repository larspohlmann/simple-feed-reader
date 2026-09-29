<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Unwraps a <button> around a content photo, which readability would drop with the button. Only an image with an
 * absolute source and both declared edges above the icon ceiling counts; an icon control stays for readability to
 * discard. It relies on LazyImageSources running first, so the source it checks is the resolved one.
 */
final readonly class ImageButtonUnwrapper implements PageRepairInterface
{
    /** An edge at or below this is an icon or a beacon; an article photo clears it. */
    private const int ICON_EDGE_CEILING = 100;

    public function repairIn(HTMLDocument $document): void
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
        if (!AbsoluteHttpUrl::matches($source)) {
            return false;
        }

        return (int) $image->getAttribute('width') > self::ICON_EDGE_CEILING
            && (int) $image->getAttribute('height') > self::ICON_EDGE_CEILING;
    }
}
