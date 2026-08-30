<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestBrandLogo;
use App\Service\Mail\Digest\Exception\ImageProcessingException;
use PHPUnit\Framework\TestCase;

final class DigestBrandLogoTest extends TestCase
{
    private function projectDir(): string
    {
        return \dirname(__DIR__, 4);
    }

    public function testBytesReturnsThePngLogoFromDisk(): void
    {
        $logo = new DigestBrandLogo($this->projectDir());

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $logo->bytes());
    }

    public function testContentTypeIsImagePng(): void
    {
        $logo = new DigestBrandLogo($this->projectDir());

        self::assertSame('image/png', $logo->contentType());
    }

    public function testBytesThrowsWhenTheProjectDirIsWrong(): void
    {
        $logo = new DigestBrandLogo($this->projectDir() . '/does-not-exist');

        $this->expectException(ImageProcessingException::class);

        @$logo->bytes();
    }
}
