<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Catalog\FetchedFavicon;

final class StubFaviconFetcher implements CatalogFaviconFetcherInterface
{
    /** @var array<string, string|\Throwable> */
    private array $byUrl = [];
    private string|\Throwable|null $default = null;
    private string $contentType = 'image/png';

    public function willReturnBytes(string $url, string $bytes): void
    {
        $this->byUrl[$url] = $bytes;
    }

    public function willFail(string $url, \Throwable $error): void
    {
        $this->byUrl[$url] = $error;
    }

    public function willAlwaysReturn(string $bytes): void
    {
        $this->default = $bytes;
    }

    public function willAlwaysFail(\Throwable $error): void
    {
        $this->default = $error;
    }

    public function download(string $iconUrl): FetchedFavicon
    {
        $result = $this->byUrl[$iconUrl] ?? $this->default;
        if ($result === null) {
            throw new FaviconUnavailableException('no stub configured for ' . $iconUrl);
        }
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return new FetchedFavicon($iconUrl, $result, $this->contentType);
    }
}
