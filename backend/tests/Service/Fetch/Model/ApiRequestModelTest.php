<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ApiRequestModel;
use PHPUnit\Framework\TestCase;

final class ApiRequestModelTest extends TestCase
{
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
}
