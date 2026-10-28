<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbeInterface;
use App\Service\Image\Model\ImageDimensionsModel;

final class StubGuardedImageProbe implements GuardedImageProbeInterface
{
    /** @var array<string, true> */
    private array $existing = [];

    /** @var array<string, ImageDimensionsModel> */
    private array $measured = [];

    /** @var list<string> */
    private array $requested = [];

    public function willExist(string $url): void
    {
        $this->existing[$url] = true;
    }

    public function willMeasure(string $url, int $width, int $height): void
    {
        $this->measured[$url] = new ImageDimensionsModel($width, $height);
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return $this->requested;
    }

    public function exists(string $imageUrl): bool
    {
        $this->requested[] = $imageUrl;

        return isset($this->existing[$imageUrl]);
    }

    public function measure(string $imageUrl): ImageDimensionsModel
    {
        $this->requested[] = $imageUrl;

        return $this->measured[$imageUrl] ?? throw new GuardedImageUnavailableException('not stubbed: ' . $imageUrl);
    }
}
