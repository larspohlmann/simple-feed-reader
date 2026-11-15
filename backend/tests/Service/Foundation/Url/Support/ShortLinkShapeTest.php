<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Url\Support;

use App\Service\Foundation\Url\Support\ShortLinkShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShortLinkShapeTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'opaque token' => ['https://n.pr/4kcyxuV', true];
        yield 'mixed-case token' => ['https://trib.al/X9b8h3C', true];
        yield 'trailing slash' => ['https://bit.ly/4iQI7RJ/', true];
        yield 'bang token' => ['https://taz.de/!6227406', true];
        yield 'token with a query' => ['https://youtu.be/IBJ4QotdjPI?t=12', true];
        yield 'one word with a query' => ['https://www.youtube.com/watch?v=abc', true];
        yield 'front page' => ['https://example.com/', false];
        yield 'no path' => ['https://example.com', false];
        yield 'two segments' => ['https://news.netasgard.com/a/35438417fdec504f', false];
        yield 'hyphenated slug' => ['https://stadt-bremerhaven.de/capy-reader-schlanker-open-source', false];
        yield 'file name' => ['https://atlasflux.saynete.com/atlas_des_flux.htm', false];
        yield 'not a url' => ['not a url', false];
        yield 'no host' => ['/4kcyxuV', false];
    }

    #[DataProvider('urls')]
    public function testFits(string $url, bool $fits): void
    {
        self::assertSame($fits, ShortLinkShape::fits($url));
    }
}
