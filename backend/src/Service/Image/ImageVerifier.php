<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\Entry;
use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconRejectedException;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Clock\NaiveUtcClock;

/**
 * Verifies one pending image via the SSRF-guarded fetcher, retrying a bounded
 * number of times before dropping it. A refusal the fetcher cannot recover
 * from keeps the image unmeasured instead — a browser may still render it.
 */
final readonly class ImageVerifier
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private CatalogFaviconFetcherInterface $fetcher,
        private NaiveUtcClock $clock,
    ) {
    }

    public function verify(Entry $entry): ImageVerifyOutcome
    {
        $url = $entry->getImage()->getUrl();
        if ($url === null) {
            $entry->dropImage($this->clock->now());

            return ImageVerifyOutcome::Dropped;
        }

        try {
            $bytes = $this->fetcher->download($url)->bytes;
        } catch (FaviconRejectedException) {
            $entry->getImage()->keepUnmeasured($this->clock->now());

            return ImageVerifyOutcome::Kept;
        } catch (FaviconUnavailableException) {
            return $this->recordFailure($entry);
        }

        return $this->judge($entry, $bytes);
    }

    private function judge(Entry $entry, string $bytes): ImageVerifyOutcome
    {
        $dimensions = ImageDimensions::fromBytes($bytes);
        if ($dimensions === null) {
            return $this->recordFailure($entry);
        }
        if ($dimensions->isBeacon()) {
            $entry->dropImage($this->clock->now());

            return ImageVerifyOutcome::Dropped;
        }

        $entry->getImage()->recordMeasurement($dimensions->width, $dimensions->height, $this->clock->now());

        return ImageVerifyOutcome::Measured;
    }

    private function recordFailure(Entry $entry): ImageVerifyOutcome
    {
        $image = $entry->getImage();
        if ($image->getVerifyAttempts() + 1 >= self::MAX_ATTEMPTS) {
            $entry->dropImage($this->clock->now());

            return ImageVerifyOutcome::Dropped;
        }

        $image->recordFailedProbe();

        return ImageVerifyOutcome::Retried;
    }
}
