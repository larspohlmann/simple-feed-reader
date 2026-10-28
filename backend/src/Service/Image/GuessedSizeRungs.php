<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\Pass\SizeWalk;
use App\Service\Image\Support\RenditionTargets;
use App\Service\Image\Support\SizedImageUrl;

/** Size variants a CMS stores beside an upload but no feed declares, found by naming them and asking. */
final readonly class GuessedSizeRungs
{
    /** @var list<int> */
    private const array GUESSED_WIDTHS = [300, 384, 512, 640, 768, 1024, 1280];

    private const string WORDPRESS_UPLOADS = '/wp-content/uploads/';

    public function __construct(private GuardedImageProbeInterface $probe)
    {
    }

    /** @return list<ImageRendition> */
    public function rungsFor(LeadImageModel $lead): array
    {
        if (SizedImageUrl::hasSizeSuffix($lead->url)) {
            return $this->walk($this->fromOriginalOrLead($lead));
        }
        if (str_contains($lead->url, self::WORDPRESS_UPLOADS)) {
            return $this->walk(new SizeWalk($lead->url, $lead->dimensions, $lead->knownWidths()));
        }

        return [];
    }

    private function fromOriginalOrLead(LeadImageModel $lead): SizeWalk
    {
        $originalUrl = SizedImageUrl::withoutSizeSuffix($lead->url);
        try {
            $original = $this->probe->measure($originalUrl);
        } catch (GuardedImageUnavailableException) {
            return new SizeWalk($lead->url, $lead->dimensions, $lead->knownWidths());
        }
        $walk = new SizeWalk($originalUrl, $original, $lead->knownWidths());
        if ($original->width <= RenditionTargets::LARGEST && $original->width !== $lead->dimensions->width) {
            $walk->keep(new ImageRendition($originalUrl, $original->width));
        }

        return $walk;
    }

    /** @return list<ImageRendition> */
    private function walk(SizeWalk $walk): array
    {
        foreach (self::GUESSED_WIDTHS as $width) {
            if ($walk->wants($width) && $this->probe->exists($walk->candidateAt($width))) {
                $walk->keep(new ImageRendition($walk->candidateAt($width), $width));
            }
        }

        return $walk->found();
    }
}
