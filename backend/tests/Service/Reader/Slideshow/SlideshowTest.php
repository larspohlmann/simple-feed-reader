<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Reader\Slideshow\Slide;
use App\Service\Reader\Slideshow\Slideshow;
use PHPUnit\Framework\TestCase;

final class SlideshowTest extends TestCase
{
    public function testTwoSlidesProduceASlideshow(): void
    {
        $show = Slideshow::fromSlides(
            [new Slide('https://img/1.jpg', 'one'), new Slide('https://img/2.jpg', 'two')],
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
        self::assertNull(Slideshow::fromSlides([new Slide('https://img/1.jpg', 'one')], null, null, null));
    }

    public function testEmptyIsRejected(): void
    {
        self::assertNull(Slideshow::fromSlides([], null, null, null));
    }
}
