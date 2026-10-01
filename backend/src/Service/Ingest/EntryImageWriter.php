<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Url\Support\HttpsImageUrl;

/** Stores a feed-declared image on an entry, pending the background verify: declared dimensions don't prove it loads. */
final readonly class EntryImageWriter
{
    /** Whether the image had a URL worth storing; one without leaves the entry as it was. */
    public function write(Entry $entry, DeclaredImageModel $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        $entry->getImage()->storePending($url, $image->width, $image->height);

        return true;
    }

    public function writeOrMarkNone(Entry $entry, ?DeclaredImageModel $image): void
    {
        if ($image === null || !$this->write($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }
}
