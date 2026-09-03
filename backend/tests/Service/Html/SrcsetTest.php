<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\Srcset;
use PHPUnit\Framework\TestCase;

final class SrcsetTest extends TestCase
{
    public function testTakesTheFirstCandidateUrl(): void
    {
        self::assertSame('a.jpg', Srcset::firstUrl('a.jpg 1x, b.jpg 2x'));
    }

    public function testDropsTheWidthDescriptor(): void
    {
        self::assertSame('a.jpg', Srcset::firstUrl('a.jpg 800w'));
    }

    public function testTakesABareUrlWithoutADescriptor(): void
    {
        self::assertSame('a.jpg', Srcset::firstUrl('a.jpg'));
    }

    public function testReturnsNullForNullOrBlank(): void
    {
        self::assertNull(Srcset::firstUrl(null));
        self::assertNull(Srcset::firstUrl(''));
        self::assertNull(Srcset::firstUrl('   '));
    }

    public function testWidestPicksTheGreatestWidthDescriptor(): void
    {
        $widest = Srcset::widest('a.jpg 300w, b.jpg 800w, c.jpg 500w');

        self::assertNotNull($widest);
        self::assertSame('b.jpg', $widest->url);
        self::assertSame(800, $widest->width);
    }

    public function testWidestKeepsTheFirstCandidateWhenNoneDeclareAWidth(): void
    {
        $widest = Srcset::widest('a.jpg, b.jpg');

        self::assertNotNull($widest);
        self::assertSame('a.jpg', $widest->url);
        self::assertNull($widest->width);
    }

    public function testWidestPrefersAMeasuredCandidateOverAnEarlierBareOne(): void
    {
        $widest = Srcset::widest('a.jpg, b.jpg 400w');

        self::assertNotNull($widest);
        self::assertSame('b.jpg', $widest->url);
        self::assertSame(400, $widest->width);
    }

    public function testWidestKeepsAnEarlierWiderCandidateOverALaterNarrowerOne(): void
    {
        $widest = Srcset::widest('a.jpg 900w, b.jpg 200w');

        self::assertNotNull($widest);
        self::assertSame('a.jpg', $widest->url);
        self::assertSame(900, $widest->width);
    }

    public function testWidestReadsAWidthOnlyFromAWidthDescriptor(): void
    {
        // A density descriptor is not a width.
        self::assertNull(Srcset::widest('a.jpg 2x')?->width);
    }

    public function testWidestRejectsAWidthWithTrailingText(): void
    {
        // "800wide" is not a width descriptor: the trailing anchor must reject it.
        self::assertNull(Srcset::widest('a.jpg 800wide')?->width);
    }

    public function testWidestRejectsAWidthWithLeadingText(): void
    {
        // "x800w" is not a width descriptor: the leading anchor must reject it.
        self::assertNull(Srcset::widest('a.jpg x800w')?->width);
    }

    public function testWidestTrimsWhitespaceAroundCandidates(): void
    {
        $widest = Srcset::widest('  a.jpg 800w ,  b.jpg 300w ');

        self::assertNotNull($widest);
        self::assertSame('a.jpg', $widest->url);
        self::assertSame(800, $widest->width);
    }

    public function testKeepsCommasInsideTheFirstCandidateUrl(): void
    {
        // Substack serves Cloudinary transform URLs that spell their options
        // "w_320,c_limit,f_auto" (entry 487639). Only a comma that ends a URL
        // separates candidates, so these commas belong to the URL.
        self::assertSame(
            'https://cdn.test/fetch/w_320,c_limit,f_auto/a.png',
            Srcset::firstUrl('https://cdn.test/fetch/w_320,c_limit,f_auto/a.png 320w, b.png 800w'),
        );
    }

    public function testWidestKeepsCommasInsideTheCandidateUrl(): void
    {
        $widest = Srcset::widest(
            'https://cdn.test/fetch/w_320,c_limit,f_auto/a.png 320w, '
            . 'https://cdn.test/fetch/w_1456,c_limit,f_auto/a.png 1456w',
        );

        self::assertNotNull($widest);
        self::assertSame('https://cdn.test/fetch/w_1456,c_limit,f_auto/a.png', $widest->url);
        self::assertSame(1456, $widest->width);
    }

    public function testSeparatesBareCandidatesOnTheCommaThatEndsAUrl(): void
    {
        // Without descriptors the comma is the only separator, and it must not
        // be left on the URL it terminates.
        self::assertSame(
            'https://cdn.test/fetch/w_320,f_auto/a.png',
            Srcset::firstUrl('https://cdn.test/fetch/w_320,f_auto/a.png, https://cdn.test/b.png'),
        );
    }

    public function testWidestPrefersTheDensestCandidateWhenNoneDeclareAWidth(): void
    {
        // zeit lists "photo, photo__scale_2 2x" per source (entry 497686). The
        // 2x candidate is the larger file, so a single-rendition reader wants it.
        $widest = Srcset::widest('a.jpg, b.jpg 2x, c.jpg 1.5x');

        self::assertNotNull($widest);
        self::assertSame('b.jpg', $widest->url);
        self::assertNull($widest->width);
    }

    public function testWidestKeepsAnEarlierDenserCandidateOverALaterSparserOne(): void
    {
        self::assertSame('a.jpg', Srcset::widest('a.jpg 3x, b.jpg 2x')?->url);
    }

    public function testWidestReadsABareCandidateAsOneX(): void
    {
        self::assertSame('b.jpg', Srcset::widest('a.jpg 0.5x, b.jpg')?->url);
    }

    public function testWidestKeepsTheFirstOfTwoEqualWidths(): void
    {
        self::assertSame('a.jpg', Srcset::widest('a.jpg 800w, b.jpg 800w')?->url);
    }

    public function testWidestPrefersAMeasuredCandidateOverADenserBareOne(): void
    {
        self::assertSame('b.jpg', Srcset::widest('a.jpg 2x, b.jpg 400w')?->url);
    }

    public function testWidestReturnsNullForNull(): void
    {
        self::assertNull(Srcset::widest(null));
    }
}
