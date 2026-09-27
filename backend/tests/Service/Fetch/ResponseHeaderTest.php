<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\ResponseHeader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseHeaderTest extends TestCase
{
    public function testReadsTheFirstValueOfARepeatedHeader(): void
    {
        $response = $this->response(new MockResponse('', ['response_headers' => ['link' => ['<a>', '<b>']]]));

        self::assertSame('<a>', ResponseHeader::first($response, 'link'));
    }

    public function testAnAbsentHeaderHasNoValue(): void
    {
        self::assertNull(ResponseHeader::first($this->response(new MockResponse('')), 'location'));
    }

    public function testAnErrorStatusStillShowsItsHeaders(): void
    {
        $response = $this->response(new MockResponse('', [
            'http_code' => 503,
            'response_headers' => ['retry-after' => ['120']],
        ]));

        self::assertSame('120', ResponseHeader::first($response, 'retry-after'));
    }

    public function testAResponseThatCannotBeReadHasNoHeaders(): void
    {
        $response = $this->response(new MockResponse('', ['error' => 'connection reset']));

        self::assertNull(ResponseHeader::first($response, 'location'));
    }

    private function response(MockResponse $mock): ResponseInterface
    {
        return (new MockHttpClient($mock))->request('GET', 'https://example.test/');
    }
}
