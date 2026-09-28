<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Url\HttpsImageUrl;

/** Stores a feed-declared image on an entry: trusted at ingest when natively https and fully sized, else pending. */
final readonly class EntryImageWriter
{
    public function __construct(private NaiveUtcClock $clock)
    {
    }

    /** Whether the image had a URL worth storing; one without leaves the entry as it was. */
    public function write(Entry $entry, DeclaredImageModel $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        if (self::trustedAtIngest($image)) {
            $entry->getImage()->storeVerified($url, $image->width, $image->height, $this->clock->now());
        } else {
            $entry->getImage()->storePending($url, $image->width, $image->height);
        }

        return true;
    }

    public function writeOrMarkNone(Entry $entry, ?DeclaredImageModel $image): void
    {
        if ($image === null || !$this->write($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    private static function trustedAtIngest(DeclaredImageModel $image): bool
    {
        return $image->width !== null
            && $image->height !== null
            && !$image->declaresBeacon()
            && HttpsImageUrl::isNativeHttps($image->url);
    }
}
