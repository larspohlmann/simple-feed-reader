<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow\Model;

use App\Service\Reader\Slideshow\Model\SlideModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
use PHPUnit\Framework\TestCase;

final class SlideshowModelTest extends TestCase
{
    public function testTwoSlidesProduceASlideshow(): void
    {
        $show = SlideshowModel::fromSlides(
            [new SlideModel('https://img/1.jpg', 'one'), new SlideModel('https://img/2.jpg', 'two')],
            'Gallery',
            'Some preceding paragraph text that is long enough.',
            null,
        );

        self::assertNotNull($show);
        self::assertCount(2, $show->slides);
        self::assertSame('Gallery', $show->title);
        self::assertSame('Some preceding paragraph text that is long enough.', $show->precedingText);
        self::assertNull($show->container);
    }

    public function testOneSlideIsRejected(): void
    {
        self::assertNull(SlideshowModel::fromSlides([new SlideModel('https://img/1.jpg', 'one')], null, null, null));
    }

    public function testEmptyIsRejected(): void
    {
        self::assertNull(SlideshowModel::fromSlides([], null, null, null));
    }

    public function testSlidesSharingOneImageAreRejected(): void
    {
        $placeholder = 'https://static.toiimg.com/photo/83033472.cms';

        self::assertNull(SlideshowModel::fromSlides(
            [
                new SlideModel($placeholder, 'one'),
                new SlideModel($placeholder, 'two'),
                new SlideModel($placeholder, 'three'),
            ],
            null,
            null,
            null,
        ));
    }
}
