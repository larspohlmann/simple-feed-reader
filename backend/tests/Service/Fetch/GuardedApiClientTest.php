<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GuardedApiClientTest extends TestCase
{
    private function client(MockHttpClient $http): GuardedApiClient
    {
        $guard = new UrlGuard(
            new StaticDnsResolver(['api.example.test' => ['93.184.216.34'], 'lan.example.test' => ['192.168.1.5']]),
            new IpValidator(),
        );

        return new GuardedApiClient($http, $guard, 'TestAgent/1.0');
    }

    public function testAPostSendsJsonPinnedToTheGuardedAddressWithoutFollowingRedirects(): void
    {
        $seenMethod = null;
        $seenUrl = null;
        $seenOptions = [];
        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seenMethod, &$seenUrl, &$seenOptions) {
                $seenMethod = $method;
                $seenUrl = $url;
                $seenOptions = $options;

                return new MockResponse('{"ok":true}', ['http_code' => 200]);
            },
        );

        $response = $this->client($http)->send(
            ApiRequestModel::postJson('https://api.example.test/x', ['a' => 1], ['Authorization' => 'Bearer t']),
        );

        self::assertSame('POST', $seenMethod);
        self::assertSame('https://api.example.test/x', $seenUrl);
        self::assertSame('{"a":1}', $seenOptions['body']);
        self::assertSame(0, $seenOptions['max_redirects']);
        self::assertSame(['api.example.test' => '93.184.216.34'], $seenOptions['resolve']);
        self::assertIsArray($seenOptions['headers']);
        self::assertContains('Authorization: Bearer t', $seenOptions['headers']);
        self::assertContains('User-Agent: TestAgent/1.0', $seenOptions['headers']);
        self::assertTrue($response->isSuccessful());
        self::assertSame(['ok' => true], $response->json());
    }

    public function testAnErrorStatusIsReturnedNotThrown(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"AuthenticationRequired"}', ['http_code' => 401]));

        $response = $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame(401, $response->status);
        self::assertFalse($response->isSuccessful());
        self::assertSame(['error' => 'AuthenticationRequired'], $response->json());
    }

    public function testAPrivateTargetIsRefusedBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('No request may leave for a private address.'));

        $this->expectException(SsrfBlockedException::class);

        $this->client($http)->send(ApiRequestModel::get('https://lan.example.test/x'));
    }

    public function testANonJsonBodyReadsAsEmpty(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>', ['http_code' => 502]));

        self::assertSame([], $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'))->json());
    }
}
