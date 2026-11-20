<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Url\Support;

use App\Service\Foundation\Url\Support\HttpsImageUrl;
use PHPUnit\Framework\TestCase;

final class HttpsImageUrlTest extends TestCase
{
    public function testUpgradesHttpToHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::parseUrlUpgradingHttp('http://i/x.jpg'));
    }

    public function testUpgradesProtocolRelativeToHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::parseUrlUpgradingHttp('//i/x.jpg'));
    }

    public function testKeepsNativeHttps(): void
    {
        self::assertSame('https://i/x.jpg', HttpsImageUrl::parseUrlUpgradingHttp('https://i/x.jpg'));
    }

    public function testRejectsDataUri(): void
    {
        self::assertNull(HttpsImageUrl::parseUrlUpgradingHttp('data:image/png;base64,AAAA'));
    }

    public function testRejectsSiteRelative(): void
    {
        self::assertNull(HttpsImageUrl::parseUrlUpgradingHttp('/img/x.jpg'));
    }

    public function testRejectsJavascriptScheme(): void
    {
        self::assertNull(HttpsImageUrl::parseUrlUpgradingHttp('javascript:alert(1)'));
    }

    public function testRejectsNull(): void
    {
        self::assertNull(HttpsImageUrl::parseUrlUpgradingHttp(null));
    }

    public function testRejectsAUrlThatExceedsTheLimitOnlyAfterUpgrade(): void
    {
        $httpAtLimit = 'http://' . str_repeat('a', HttpsImageUrl::MAX_LENGTH - 7);
        self::assertSame(HttpsImageUrl::MAX_LENGTH, mb_strlen($httpAtLimit));
        self::assertNull(HttpsImageUrl::parseUrlUpgradingHttp($httpAtLimit));
    }

    public function testKeepsAUrlThatIsExactlyTheLimitAfterUpgrade(): void
    {
        $httpJustUnder = 'http://' . str_repeat('a', HttpsImageUrl::MAX_LENGTH - 8);
        $upgraded = HttpsImageUrl::parseUrlUpgradingHttp($httpJustUnder);
        self::assertNotNull($upgraded);
        self::assertSame(HttpsImageUrl::MAX_LENGTH, mb_strlen($upgraded));
    }

    public function testDoesNotChangeTheStrictGate(): void
    {
        self::assertNull(HttpsImageUrl::parseUrl('http://i/x.jpg'));
    }

    public function testOrNullAcceptsAnUppercaseHttpsScheme(): void
    {
        self::assertSame('https://Host/a.jpg', HttpsImageUrl::parseUrl('HTTPS://Host/a.jpg'));
    }

    public function testOrNullUpgradingAcceptsAnUppercaseHttpScheme(): void
    {
        self::assertSame('https://Host/a.jpg', HttpsImageUrl::parseUrlUpgradingHttp('HTTP://Host/a.jpg'));
    }

    public function testOrNullRejectsAnUppercaseHttpScheme(): void
    {
        self::assertNull(HttpsImageUrl::parseUrl('HTTP://Host/a.jpg'));
    }
}
