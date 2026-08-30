<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

interface DigestImageResizerInterface
{
    /** Scale-and-centre-crop to exactly $width×$height, re-encoded as JPEG. */
    public function coverJpeg(string $sourceBytes, int $width, int $height): string;

    /** Fit within $width×$height, centred on a transparent canvas, as PNG. */
    public function containPng(string $sourceBytes, int $width, int $height): string;
}
