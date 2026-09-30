<?php

declare(strict_types=1);

namespace App\Service\Image\ImageProxy;

use App\Service\Image\Exception\ImageUnavailableException;
use App\Service\Image\Exception\InvalidImageUrlException;
use App\Service\Image\Model\ProxiedImageModel;

interface ImageProxyInterface
{
    /**
     * @throws InvalidImageUrlException
     * @throws ImageUnavailableException
     */
    public function fetch(string $url): ProxiedImageModel;
}
