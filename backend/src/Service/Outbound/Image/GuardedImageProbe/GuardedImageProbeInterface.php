<?php

declare(strict_types=1);

namespace App\Service\Image\GuardedImageProbe;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Model\ImageDimensionsModel;

interface GuardedImageProbeInterface
{
    /**
     * False when the host answered but holds no such image; the expected width is checked where the host cannot
     * answer a HEAD.
     *
     * @throws GuardedImageUnavailableException when the host could not be reached
     */
    public function exists(string $imageUrl, int $expectedWidth): bool;

    /** @throws GuardedImageUnavailableException */
    public function measure(string $imageUrl): ImageDimensionsModel;
}
