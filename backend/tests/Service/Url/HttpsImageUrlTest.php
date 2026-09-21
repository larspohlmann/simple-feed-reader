<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\HttpsImageUrl;
use PHPUnit\Framework\TestCase;

final class HttpsImageUrlTest extends TestCase
{
    public function testUpgradesHttpToHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::orNullUpgrading('http://i/x.jpg'));
    }

    public function testUpgradesProtocolRelativeToHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::orNullUpgrading('//i/x.jpg'));
    }

    public function testKeepsNativeHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::orNullUpgrading('https://i/x.jpg'));
    }

    public function testRejectsDataUri(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading('data:image/png;base64,AAAA'));
    }

    public function testRejectsSiteRelative(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading('/img/x.jpg'));
    }

    public function testRejectsJavascriptScheme(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading('javascript:alert(1)'));
    }

    public function testRejectsNull(): void
    {
        self::assertNull(HttpsImageUrl::orNullUpgrading(null));
    }

    public function testRejectsAUrlThatExceedsTheLimitOnlyAfterUpgrade(): void
    {
        $httpAtLimit = 'http://' . str_repeat('a', HttpsImageUrl::MAX_LENGTH - 7);
        self::assertSame(HttpsImageUrl::MAX_LENGTH, mb_strlen($httpAtLimit));
        self::assertNull(HttpsImageUrl::orNullUpgrading($httpAtLimit));
    }

    public function testKeepsAUrlThatIsExactlyTheLimitAfterUpgrade(): void
    {
        $httpJustUnder = 'http://' . str_repeat('a', HttpsImageUrl::MAX_LENGTH - 8);
        $upgraded = HttpsImageUrl::orNullUpgrading($httpJustUnder);
        self::assertNotNull($upgraded);
        self::assertSame(HttpsImageUrl::MAX_LENGTH, mb_strlen($upgraded));
    }

    public function testDoesNotChangeTheStrictGate(): void
    {
        self::assertNull(HttpsImageUrl::orNull('http://i/x.jpg'));
    }

    public function testOrNullAcceptsAnUppercaseHttpsScheme(): void
    {
        self::assertSame('https://Host/a.jpg', HttpsImageUrl::orNull('HTTPS://Host/a.jpg'));
    }

    public function testOrNullUpgradingAcceptsAnUppercaseHttpScheme(): void
    {
        self::assertSame('https://Host/a.jpg', HttpsImageUrl::orNullUpgrading('HTTP://Host/a.jpg'));
    }

    public function testOrNullRejectsAnUppercaseHttpScheme(): void
    {
        self::assertNull(HttpsImageUrl::orNull('HTTP://Host/a.jpg'));
    }

    public function testIsNativeHttpsAcceptsAnUppercaseHttpsScheme(): void
    {
        self::assertTrue(HttpsImageUrl::isNativeHttps('HTTPS://h/a'));
    }

    public function testIsNativeHttpsRejectsAProtocolRelativeUrl(): void
    {
        self::assertFalse(HttpsImageUrl::isNativeHttps('//h/a'));
    }

    public function testIsNativeHttpsRejectsHttp(): void
    {
        self::assertFalse(HttpsImageUrl::isNativeHttps('http://h/a'));
    }
}
