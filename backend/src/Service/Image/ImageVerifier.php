<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\EntryImage;
use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Clock\NaiveUtcClock;

/**
 * Verifies one pending image via the SSRF-guarded fetcher, retrying a bounded
 * number of times before dropping it, so a transient network failure never nulls a good image.
 */
final readonly class ImageVerifier
{
    private const int BEACON_EDGE_CEILING = 100;
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private CatalogFaviconFetcherInterface $fetcher,
        private NaiveUtcClock $clock,
    ) {
    }

    public function verify(EntryImage $image): ImageVerifyOutcome
    {
        $url = $image->getUrl();
        if ($url === null) {
            return ImageVerifyOutcome::Dropped;
        }

        try {
            $bytes = $this->fetcher->download($url)->bytes;
        } catch (FaviconUnavailableException) {
            return $this->recordFailure($image);
        }

        $dimensions = ImageDimensions::fromBytes($bytes);
        if ($dimensions === null) {
            return $this->recordFailure($image);
        }
        if ($dimensions->bothEdgesAtMost(self::BEACON_EDGE_CEILING)) {
            $image->drop();

            return ImageVerifyOutcome::Dropped;
        }

        $image->recordMeasurement($dimensions->width, $dimensions->height, $this->clock->now());

        return ImageVerifyOutcome::Measured;
    }

    private function recordFailure(EntryImage $image): ImageVerifyOutcome
    {
        if ($image->getVerifyAttempts() + 1 >= self::MAX_ATTEMPTS) {
            $image->drop();

            return ImageVerifyOutcome::Dropped;
        }

        $image->recordFailedProbe();

        return ImageVerifyOutcome::Retried;
    }
}
