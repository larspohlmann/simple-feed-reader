<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Service\Image\Model\DeclaredImageModel;

/**
 * The Original (feed-body) view's lead picture: the feed's item image, shown only when the feed body carries no image
 * of its own. The Reader view's lead rides inside the extracted body instead (ReaderLeadImage).
 */
final readonly class OriginalHeroResolver
{
    public function __construct(private HeroImageSelector $selector)
    {
    }

    public function resolve(Entry $entry): ?DeclaredImageModel
    {
        return $this->selector->select($this->feedPicture($entry), $this->feedBody($entry));
    }

    private function feedPicture(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }

    /** The body the original view renders. */
    private function feedBody(Entry $entry): string
    {
        return $entry->getContentHtml() ?? $entry->getSummary() ?? '';
    }
}
