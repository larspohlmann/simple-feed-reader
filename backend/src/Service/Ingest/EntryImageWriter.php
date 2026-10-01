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
        $entry->getImage()->storeRenditions(self::storableRenditions($image->renditions, $url));

        return true;
    }

    public function writeOrMarkNone(Entry $entry, ?DeclaredImageModel $image): void
    {
        if ($image === null || !$this->write($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    /**
     * The renditions under the image URL's own https rule. Only a rung that is not the lead image can pair with it,
     * so a single rung counts only then; one alone that is the lead image leaves the browser no choice.
     *
     * @param list<ImageRendition> $declared
     *
     * @return list<ImageRendition>
     */
    private static function storableRenditions(array $declared, string $leadUrl): array
    {
        $ladder = ImageRendition::ladder(self::secureRenditions($declared));
        if (\count($ladder) >= self::FEWEST_RENDITIONS_TO_CHOOSE_FROM) {
            return $ladder;
        }

        return array_values(array_filter($ladder, static fn (ImageRendition $rung): bool => $rung->url !== $leadUrl));
    }

    /**
     * @param list<ImageRendition> $declared
     *
     * @return list<ImageRendition>
     */
    private static function secureRenditions(array $declared): array
    {
        $secure = [];
        foreach ($declared as $rendition) {
            $url = HttpsImageUrl::orNullUpgrading($rendition->url);
            if ($url !== null && self::fitsASrcsetCandidate($url)) {
                $secure[] = new ImageRendition($url, $rendition->width);
            }
        }

        return $secure;
    }

    private static function fitsASrcsetCandidate(string $url): bool
    {
        return preg_match('/\s/', $url) !== 1;
    }
}
