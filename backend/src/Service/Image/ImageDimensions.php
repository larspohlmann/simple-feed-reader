<?php

declare(strict_types=1);

namespace App\Service\Image;

/**
 * The real pixel dimensions of image bytes, read once via GD. Null when the
 * bytes are not a decodable image, which the caller treats as a failed probe.
 */
final readonly class ImageDimensions
{
    private const int BEACON_EDGE_CEILING = 100;

    public function __construct(
        public int $width,
        public int $height,
    ) {
    }

    public static function fromBytes(string $bytes): ?self
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            return null;
        }

        return new self($size[0], $size[1]);
    }

    public function isBeacon(): bool
    {
        return $this->width <= self::BEACON_EDGE_CEILING && $this->height <= self::BEACON_EDGE_CEILING;
    }
}
