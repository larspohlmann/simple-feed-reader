<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\LandedResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LandedResponseTest extends TestCase
{
    public function testHeaderDelegatesToResponseHeader(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'response_headers' => ['content-type' => ['text/html; charset=gbk']],
        ]));
        $response = $client->request('GET', 'https://example.com/');

        $landed = new LandedResponse('https://example.com/', 200, $response);

        self::assertSame('text/html; charset=gbk', $landed->header('content-type'));
    }
}
