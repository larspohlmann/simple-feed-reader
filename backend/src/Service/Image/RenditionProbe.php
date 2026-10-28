<?php

declare(strict_types=1);

namespace App\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Model\LeadImageModel;

final readonly class RenditionProbe
{
    public function __construct(
        private ResizerRungs $resizerRungs,
        private GuessedSizeRungs $guessedSizeRungs,
    ) {
    }

    /** @return list<ImageRendition> */
    public function rungsFor(LeadImageModel $lead): array
    {
        if ($this->resizerRungs->resizes($lead)) {
            return $this->resizerRungs->rungsFor($lead);
        }

        return $this->guessedSizeRungs->rungsFor($lead);
    }
}
