<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Model;

use App\Service\Html\Model\ImageRenditionModel;
use PHPUnit\Framework\TestCase;

final class ImageRenditionModelTest extends TestCase
{
    public function testAWiderRenditionOutsizesANarrowerOne(): void
    {
        $wider = new ImageRenditionModel('https://example.com/large.jpg', 1200);
        $narrower = new ImageRenditionModel('https://example.com/small.jpg', 600);

        self::assertTrue($wider->outsizes($narrower));
    }

    public function testAnUnmeasuredRenditionNeverOutsizes(): void
    {
        $unmeasured = new ImageRenditionModel('https://example.com/unknown.jpg', null);
        $measured = new ImageRenditionModel('https://example.com/small.jpg', 600);

        self::assertFalse($unmeasured->outsizes($measured));
    }

    public function testAMeasuredRenditionOutsizesAnUnmeasuredIncumbent(): void
    {
        $measured = new ImageRenditionModel('https://example.com/large.jpg', 1200);
        $unmeasured = new ImageRenditionModel('https://example.com/unknown.jpg', null);

        self::assertTrue($measured->outsizes($unmeasured));
    }

    public function testExtractsWidthFromAUrlQueryParameter(): void
    {
        self::assertSame(750, ImageRenditionModel::widthFromUrl('https://example.com/photo.jpg?w=750'));
        self::assertSame(1024, ImageRenditionModel::widthFromUrl('https://example.com/photo.jpg?width=1024'));
        self::assertNull(ImageRenditionModel::widthFromUrl('https://example.com/photo.jpg'));
        self::assertNull(ImageRenditionModel::widthFromUrl(null));
    }
}
