<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\ImageRendition;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Url\Support\HttpsImageUrl;

/** Stores a feed-declared image on an entry, pending the background verify: declared dimensions don't prove it loads. */
final readonly class EntryImageWriter
{
    private const int FEWEST_RENDITIONS_TO_CHOOSE_FROM = 2;

    /** Whether the image had a URL worth storing; one without leaves the entry as it was. */
    public function write(Entry $entry, DeclaredImageModel $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        $entry->getImage()->storePending($url, $image->width, $image->height);
        $entry->getImage()->storeRenditions(self::storableRenditions($image->renditions));

        return true;
    }

    public function writeOrMarkNone(Entry $entry, ?DeclaredImageModel $image): void
    {
        if ($image === null || !$this->write($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    /**
     * The renditions under the image URL's own https rule; a single one leaves the browser no choice, so it is none.
     *
     * @param list<ImageRendition> $declared
     *
     * @return list<ImageRendition>
     */
    private static function storableRenditions(array $declared): array
    {
        $secure = [];
        foreach ($declared as $rendition) {
            $url = HttpsImageUrl::orNullUpgrading($rendition->url);
            if ($url !== null) {
                $secure[] = new ImageRendition($url, $rendition->width);
            }
        }
        $ladder = ImageRendition::ladder($secure);

        return \count($ladder) < self::FEWEST_RENDITIONS_TO_CHOOSE_FROM ? [] : $ladder;
    }
}
