<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Model;

use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use PHPUnit\Framework\TestCase;

final class ApiRequestModelTest extends TestCase
{
    public function testARequestWaitsTheStandardTimeUnlessToldToWaitLonger(): void
    {
        $request = ApiRequestModel::get('https://api.example.test/x', ['Accept' => 'application/json']);

        $patient = $request->waitingUpTo(25.0);

        self::assertSame(10.0, $request->timeoutSeconds);
        self::assertSame(25.0, $patient->timeoutSeconds);
        self::assertSame(
            ['GET', 'https://api.example.test/x', ['Accept' => 'application/json']],
            [$patient->method, $patient->url, $patient->headers],
        );
    }

    public function testAddedHeadersKeepHowLongTheRequestWaits(): void
    {
        $request = ApiRequestModel::get('https://api.example.test/x')->waitingUpTo(25.0);

        self::assertSame(25.0, $request->withHeaders(['Accept' => 'application/json'])->timeoutSeconds);
    }

    public function testAddedHeadersJoinTheOnesTheRequestHadAndWinOnAClash(): void
    {
        $request = ApiRequestModel::postJson('https://api.example.test/x', ['a' => 1], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer old',
        ]);

        $authorized = $request->withHeaders(['Authorization' => 'Bearer new']);

        self::assertSame(
            ['Accept' => 'application/json', 'Authorization' => 'Bearer new'],
            $authorized->headers,
        );
        self::assertSame(['POST', 'https://api.example.test/x', ['a' => 1]], [
            $authorized->method,
            $authorized->url,
            $authorized->json,
        ]);
    }

    public function testADeleteCarriesItsHeadersAndNoBody(): void
    {
        $request = ApiRequestModel::delete('https://api.example.test/x', ['Accept' => 'application/json']);

        self::assertSame(
            ['DELETE', 'https://api.example.test/x', ['Accept' => 'application/json'], null],
            [$request->method, $request->url, $request->headers, $request->json],
        );
    }

    public function testABytesPostCarriesItsBodyAndContentTypeAndKeepsTheBodyThroughHeaders(): void
    {
        $request = ApiRequestModel::postBytes('https://api.example.test/blob', 'raw-bytes', 'image/png');

        $authorized = $request->withHeaders(['Authorization' => 'Bearer jwt']);

        self::assertSame(
            ['POST', 'https://api.example.test/blob', 'raw-bytes', null],
            [$request->method, $request->url, $request->body, $request->json],
        );
        self::assertSame(['Content-Type' => 'image/png'], $request->headers);
        self::assertSame('raw-bytes', $authorized->body);
        self::assertSame(['Content-Type' => 'image/png', 'Authorization' => 'Bearer jwt'], $authorized->headers);
    }
}
