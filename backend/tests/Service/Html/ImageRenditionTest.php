<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\ImageRendition;
use PHPUnit\Framework\TestCase;

final class ImageRenditionTest extends TestCase
{
    public function testAWiderRenditionOutsizesANarrowerOne(): void
    {
        $wider = new ImageRendition('https://example.com/large.jpg', 1200);
        $narrower = new ImageRendition('https://example.com/small.jpg', 600);

        self::assertTrue($wider->outsizes($narrower));
    }

    public function testAnUnmeasuredRenditionNeverOutsizes(): void
    {
        $unmeasured = new ImageRendition('https://example.com/unknown.jpg', null);
        $measured = new ImageRendition('https://example.com/small.jpg', 600);

        self::assertFalse($unmeasured->outsizes($measured));
    }

    public function testAMeasuredRenditionOutsizesAnUnmeasuredIncumbent(): void
    {
        $measured = new ImageRendition('https://example.com/large.jpg', 1200);
        $unmeasured = new ImageRendition('https://example.com/unknown.jpg', null);

        self::assertTrue($measured->outsizes($unmeasured));
    }
}
