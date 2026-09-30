<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ProxiedImageResponse;
use App\Service\Image\Model\ProxiedImageModel;
use PHPUnit\Framework\TestCase;

final class ProxiedImageResponseTest extends TestCase
{
    public function testServesTheBytesPrivatelyForADayAndInertOutsideAnImg(): void
    {
        $response = ProxiedImageResponse::of(new ProxiedImageModel('avif-bytes', 'image/avif'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('avif-bytes', $response->getContent());
        self::assertSame('image/avif', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
    }
}
