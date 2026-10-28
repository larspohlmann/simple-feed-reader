<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\Entry;
use App\Entity\ImageRendition;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\Exception\GuardedImageRejectedException;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\ImageVerifyOutcome;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\ProbedLadder\ProbedLadderInterface;

/**
 * Verifies one pending image via the SSRF-guarded downloader, retrying a bounded number of times before dropping it,
 * and probes the size variants the list views need, once per image URL and never for a social network's feed, whose
 * CDN serves fixed variants by path. A refusal the downloader cannot recover from keeps the image unmeasured instead —
 * a browser may still render it.
 */
final readonly class ImageVerifier
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private GuardedImageDownloaderInterface $downloader,
        private NaiveUtcClock $clock,
        private RenditionProbe $renditionProbe,
        private ProbedLadderInterface $probedLadders,
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
            $bytes = $this->downloader->download($url)->bytes;
        } catch (GuardedImageRejectedException) {
            $entry->getImage()->keepUnmeasured($this->clock->now());

            return ImageVerifyOutcome::Kept;
        } catch (GuardedImageUnavailableException) {
            return $this->recordFailure($entry);
        }

        return $this->judge($entry, $url, $bytes);
    }

    private function judge(Entry $entry, string $url, string $bytes): ImageVerifyOutcome
    {
        $dimensions = ImageDimensionsModel::fromBytes($bytes);
        if ($dimensions === null) {
            return $this->recordFailure($entry);
        }
        if ($dimensions->isBeacon()) {
            $entry->dropImage($this->clock->now());

            return ImageVerifyOutcome::Dropped;
        }

        $entry->getImage()->recordMeasurement($dimensions->width, $dimensions->height, $this->clock->now());
        $this->addProbedRungs($entry, $url, $dimensions);

        return ImageVerifyOutcome::Measured;
    }

    private function addProbedRungs(Entry $entry, string $url, ImageDimensionsModel $dimensions): void
    {
        if ($entry->getFeed()->getNetwork() !== null) {
            return;
        }
        $image = $entry->getImage();
        $known = $image->getRenditions();
        $probed = $this->probedLadders->findProbedLadder($url)
            ?? $this->renditionProbe->rungsFor(new LeadImageModel($url, $dimensions, $known));
        if ($probed === []) {
            return;
        }
        $image->storeRenditions(ImageRendition::ladder([...$known, ...$probed]));
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
