<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\Support\RenditionTargets;
use App\Service\Image\Support\WidthQuery;

/** A host that answers `w=` with that width renders every target on demand; one check proves it. */
final readonly class ResizerRungs
{
    private const int CHECK_WIDTH = RenditionTargets::WIDTHS[0];
    private const int WIDTH_TOLERANCE = 1;
    private const int HEIGHT_TOLERANCE = 2;

    public function __construct(private GuardedImageProbeInterface $probe)
    {
    }

    public function resizes(LeadImageModel $lead): bool
    {
        if ($lead->dimensions->width < self::CHECK_WIDTH) {
            return false;
        }
        try {
            $measured = $this->probe->measure(WidthQuery::withWidth($lead->url, self::CHECK_WIDTH));
        } catch (GuardedImageUnavailableException) {
            return false;
        }

        return abs($measured->width - self::CHECK_WIDTH) <= self::WIDTH_TOLERANCE
            && abs($measured->height - self::proportionalHeight($lead)) <= self::HEIGHT_TOLERANCE;
    }

    /** @return list<ImageRendition> */
    public function rungsFor(LeadImageModel $lead): array
    {
        $rungs = [];
        foreach (RenditionTargets::uncovered($lead->knownWidths()) as $target) {
            if ($target <= $lead->dimensions->width) {
                $rungs[] = new ImageRendition(WidthQuery::withWidth($lead->url, $target), $target);
            }
        }

        return $rungs;
    }

    private static function proportionalHeight(LeadImageModel $lead): int
    {
        return (int) round(self::CHECK_WIDTH * $lead->dimensions->height / $lead->dimensions->width);
    }
}
