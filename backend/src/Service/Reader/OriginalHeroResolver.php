<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Service\Image\DeclaredImage;

/**
 * Resolves the picture that leads the Original (feed-body) view: the feed's own
 * item image, shown only when the feed body carries no image of its own.
 *
 * The Reader view no longer needs a resolver of its own — its lead picture now
 * rides inside the extracted body, restored by ReaderLeadImage during
 * extraction (#681). Only the original view, which renders the raw feed body the
 * client already holds, still needs a hero decided server-side.
 */
final readonly class OriginalHeroResolver
{
    public function __construct(private HeroImageSelector $selector)
    {
    }

    public function resolve(Entry $entry): ?DeclaredImage
    {
        return $this->selector->select($this->feedPicture($entry), $this->feedBody($entry));
    }

    private function feedPicture(Entry $entry): ?DeclaredImage
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImage($url, $entry->getImageWidth(), $entry->getImageHeight());
    }

    /** The body the original view renders. */
    private function feedBody(Entry $entry): string
    {
        return $entry->getContentHtml() ?? $entry->getSummary() ?? '';
    }
}
