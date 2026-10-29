<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\Model\DownloadedImageModel;
use Symfony\Component\Clock\MockClock;

final class StubGuardedImageDownloader implements GuardedImageDownloaderInterface
{
    /** @var array<string, string|\Throwable> */
    private array $byUrl = [];
    private string|\Throwable|null $default = null;
    private string $contentType = 'image/png';
    public int $secondsPerDownload = 0;

    public function __construct(private readonly ?MockClock $clock = null)
    {
    }

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

    public function download(string $imageUrl): DownloadedImageModel
    {
        if ($this->secondsPerDownload > 0) {
            $this->clock?->sleep($this->secondsPerDownload);
        }
        $result = $this->byUrl[$imageUrl] ?? $this->default;
        if ($result === null) {
            throw new GuardedImageUnavailableException('no stub configured for ' . $imageUrl);
        }
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return new DownloadedImageModel($imageUrl, $result, $this->contentType);
    }
}
