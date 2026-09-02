<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Media\MediaLanding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MediaLandingTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $seenOptions = [];

    /** @param list<MockResponse> $responses */
    private function landing(array $responses): MediaLanding
    {
        $queue = $responses;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$queue): MockResponse {
                $this->seenOptions = $options;

                return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
            },
        );
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);

        return new MediaLanding(
            new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
            'TestAgent/1.0',
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    public function testNamesWhereAChainLandsWithSuccess(): void
    {
        $landing = $this->landing([
            self::redirect('https://cdn.test/master.m3u8'),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ]);

        self::assertSame('https://cdn.test/master.m3u8', $landing->urlOf('https://a.test/x.m3u8'));
    }

    public function testNullWhenTheChainLandsOnAnError(): void
    {
        $landing = $this->landing([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($landing->urlOf('https://a.test/x.m3u8'));
    }

    public function testNullWhenTheChainFails(): void
    {
        $landing = $this->landing([new MockResponse('', ['error' => 'Connection reset by peer'])]);

        self::assertNull($landing->urlOf('https://a.test/x.m3u8'));
    }

    public function testSendsTheMediaAcceptHeaderAndTheTimeBudget(): void
    {
        $this->landing([new MockResponse('', ['http_code' => 200])])->urlOf('https://a.test/x.m3u8');

        /** @var list<string> $headers */
        $headers = $this->seenOptions['headers'];
        self::assertContains('Accept: application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8', $headers);
        self::assertContains('User-Agent: TestAgent/1.0', $headers);
        self::assertSame(0, $this->seenOptions['max_redirects']);
        self::assertSame(10.0, $this->seenOptions['max_duration']);
    }
}
