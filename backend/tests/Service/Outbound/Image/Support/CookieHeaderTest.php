<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\CookieHeader;
use PHPUnit\Framework\TestCase;

final class CookieHeaderTest extends TestCase
{
    public function testKeepsEachNameValuePairAndDropsTheAttributes(): void
    {
        self::assertSame('conz_bild=1; visit=7f3', CookieHeader::fromSetCookies([
            'conz_bild=1; Path=/; Max-Age=2592000; Secure; HttpOnly; SameSite=Lax',
            ' visit=7f3 ; HttpOnly',
        ]));
    }

    public function testSkipsAPairWithoutAName(): void
    {
        self::assertSame('keep=2', CookieHeader::fromSetCookies(['=orphan; Path=/', 'keep=2', 'no-equals-sign']));
    }

    public function testNoCookiesIsEmpty(): void
    {
        self::assertSame('', CookieHeader::fromSetCookies([]));
    }
}
