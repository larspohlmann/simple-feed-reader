<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RequestServingHost;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestServingHostTest extends TestCase
{
    public function testWithAMainRequestItReturnsThatRequestsHost(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('https://reader.example.com/reader'));

        $servingHost = new RequestServingHost($requests, new FixedPublicBaseUrl('https://fallback.example.org'));

        self::assertSame('reader.example.com', $servingHost->get());
    }

    public function testWithNoMainRequestItFallsBackToThePublicBaseUrlsHost(): void
    {
        $publicBaseUrl = new FixedPublicBaseUrl('https://fallback.example.org');
        $servingHost = new RequestServingHost(new RequestStack(), $publicBaseUrl);

        self::assertSame('fallback.example.org', $servingHost->get());
    }

    public function testWithNoHostInTheBaseUrlItReturnsAnEmptyString(): void
    {
        $servingHost = new RequestServingHost(new RequestStack(), new FixedPublicBaseUrl('not-a-url'));

        self::assertSame('', $servingHost->get());
    }
}
