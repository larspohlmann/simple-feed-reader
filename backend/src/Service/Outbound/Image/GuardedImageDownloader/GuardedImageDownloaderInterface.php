<?php

declare(strict_types=1);

namespace App\Service\Outbound\Image\GuardedImageDownloader;

use App\Service\Outbound\Image\Exception\GuardedImageRejectedException;
use App\Service\Outbound\Image\Exception\GuardedImageUnavailableException;
use App\Service\Outbound\Image\Model\DownloadedImageModel;

interface GuardedImageDownloaderInterface
{
    /**
     * Download the bytes of one already-resolved image URL under the SSRF/size/type guards.
     *
     * @throws GuardedImageRejectedException when the host or this policy refused; the resource may still be valid
     * @throws GuardedImageUnavailableException
     */
    public function download(string $imageUrl): DownloadedImageModel;
}
