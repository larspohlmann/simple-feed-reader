<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\CatalogFaviconResponse;
use App\Service\Catalog\CatalogFavicon;
use PHPUnit\Framework\TestCase;

final class CatalogFaviconResponseTest extends TestCase
{
    public function testServesTheBytesWithTheirTypeAnETagAndADayOfPublicCaching(): void
    {
        $response = CatalogFaviconResponse::of(new CatalogFavicon('icon-bytes', 'image/png'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('icon-bytes', $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('"' . md5('icon-bytes') . '"', $response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
    }
}
