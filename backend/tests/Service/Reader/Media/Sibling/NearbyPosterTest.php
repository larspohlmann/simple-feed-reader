<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\Sibling\NearbyPoster;
use PHPUnit\Framework\TestCase;

final class NearbyPosterTest extends TestCase
{
    public function testTakesTheLargestRenditionDeclaredAfterThePosition(): void
    {
        $html = 'id "layouts":{"1140x120":"https://a.test/assets/still-100~1140x120?cb=1",'
            . '"1920x1080":"https://a.test/assets/still-100~1920x1080?cb=1",'
            . '"384x216":"https://a.test/assets/still-100~384x216"}';

        self::assertSame('https://a.test/assets/still-100~1920x1080?cb=1', NearbyPoster::after($html, 0));
    }

    public function testAnImageExtensionCountsWithoutDimensions(): void
    {
        $html = 'id … "src":"https://a.test/img/still.jpg?w=1"';

        self::assertSame('https://a.test/img/still.jpg?w=1', NearbyPoster::after($html, 0));
    }

    public function testNeverTakesAPlaylistAFileAScriptOrAStylesheet(): void
    {
        $html = 'id "a":"https://a.test/v/master.m3u8","b":"https://a.test/v/clip.mp4",'
            . '"c":"https://a.test/app.js?v=2x2","d":"https://a.test/s.css"';

        self::assertNull(NearbyPoster::after($html, 0));
    }

    public function testLooksOnlyWithinTheWindow(): void
    {
        $html = 'id' . str_repeat(' ', 2100) . '"src":"https://a.test/still.jpg"';

        self::assertNull(NearbyPoster::after($html, 0));
    }

    public function testNullWhenNothingImageLikeFollows(): void
    {
        self::assertNull(NearbyPoster::after('id "href":"https://a.test/page"', 0));
    }
}
