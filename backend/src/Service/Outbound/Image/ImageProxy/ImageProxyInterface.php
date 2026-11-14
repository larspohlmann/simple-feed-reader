<?php

declare(strict_types=1);

namespace App\Service\Outbound\Image\ImageProxy;

use App\Service\Outbound\Image\Exception\ImageUnavailableException;
use App\Service\Outbound\Image\Exception\InvalidImageUrlException;
use App\Service\Outbound\Image\Model\ProxiedImageModel;

interface ImageProxyInterface
{
    /**
     * @throws InvalidImageUrlException
     * @throws ImageUnavailableException
     */
    public function fetch(string $url): ProxiedImageModel;
}
