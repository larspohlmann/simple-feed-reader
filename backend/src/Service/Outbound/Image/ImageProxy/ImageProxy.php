<?php

declare(strict_types=1);

namespace App\Service\Outbound\Image\ImageProxy;

use App\Service\Outbound\Image\Exception\ImageRefusedException;
use App\Service\Outbound\Image\Exception\InvalidImageUrlException;
use App\Service\Outbound\Image\ImageDownloader;
use App\Service\Outbound\Image\Model\ImageRequestModel;
use App\Service\Outbound\Image\Model\ProxiedImageModel;
use App\Service\Outbound\Image\OriginCookies;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

final readonly class ImageProxy implements ImageProxyInterface
{
    public function __construct(
        private ImageDownloader $downloader,
        private OriginCookies $originCookies,
    ) {
    }

    public function fetch(string $url): ProxiedImageModel
    {
        if (!AbsoluteHttpUrl::matches($url)) {
            throw new InvalidImageUrlException(sprintf('Not an http(s) URL: %s', $url));
        }

        try {
            return $this->downloader->download(new ImageRequestModel($url));
        } catch (ImageRefusedException $refusal) {
            return $this->retryWithOriginCookies($url, $refusal);
        }
    }

    private function retryWithOriginCookies(string $url, ImageRefusedException $refusal): ProxiedImageModel
    {
        $cookies = $this->originCookies->for($url);
        if ($cookies === '') {
            throw $refusal;
        }

        return $this->downloader->download(new ImageRequestModel($url, $cookies));
    }
}
