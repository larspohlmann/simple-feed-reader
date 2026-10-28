<?php

declare(strict_types=1);

namespace App\Service\Image\GuardedImageProbe;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Model\ImageDimensionsModel;

interface GuardedImageProbeInterface
{
    public function exists(string $imageUrl): bool;

    /** @throws GuardedImageUnavailableException */
    public function measure(string $imageUrl): ImageDimensionsModel;
}
