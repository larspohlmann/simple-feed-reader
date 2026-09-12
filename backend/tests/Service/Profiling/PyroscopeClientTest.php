<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileLabels;
use App\Service\Profiling\PyroscopeClient;
use App\Service\Profiling\PyroscopeEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PyroscopeClientTest extends TestCase
{
    private const STACKS = "main;work 3\nmain;idle 1";

    public function testPostsFoldedStacksWithTheQueryAndHeadersPyroscopeExpects(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $client = new PyroscopeClient($http, $this->endpoint('http://pyroscope:4040'));
        $client->push($this->profile(), ProfileLabels::forWebRequest('abc', 'def'));

        /**
         * @var array{
         *     method: string,
         *     url: string,
         *     options: array{body: string, timeout: float, headers: list<string>},
         * } $seen
         */
        self::assertSame('POST', $seen['method']);
        self::assertStringStartsWith('http://pyroscope:4040/ingest?', $seen['url']);
        parse_str((string) parse_url($seen['url'], \PHP_URL_QUERY), $query);
        self::assertSame(
            'simple-feed-reader{service_name=simple-feed-reader,process=web,trace_id=abc,span_id=def}',
            $query['name'],
        );
        self::assertSame('1700000000', $query['from']);
        self::assertSame('1700000005', $query['until']);
        self::assertSame('1000', $query['sampleRate']);
        self::assertSame('excimer', $query['spyName']);
        self::assertSame(self::STACKS, $seen['options']['body']);
        self::assertSame(1.0, $seen['options']['timeout']);
        self::assertContains('Content-Type: text/plain', $seen['options']['headers']);
    }

    public function testATrailingSlashOnThePushUrlIsTolerated(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url];

            return new MockResponse('', ['http_code' => 200]);
        });

        $client = new PyroscopeClient($http, $this->endpoint('http://pyroscope:4040/'));
        $client->push($this->profile(), ProfileLabels::forWorker());

        self::assertStringStartsWith('http://pyroscope:4040/ingest?', $seen['url']);
    }

    public function testNoRequestIsMadeWhenTheEndpointHasNoUrl(): void
    {
        $called = false;
        $http = new MockHttpClient(function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse();
        });

        $client = new PyroscopeClient($http, $this->endpoint(null));
        $client->push($this->profile(), ProfileLabels::forWorker());

        self::assertFalse($called);
    }

    public function testATransportErrorIsSwallowed(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'boom']));
        $client = new PyroscopeClient($http, $this->endpoint('http://pyroscope:4040'));

        $client->push($this->profile(), ProfileLabels::forWorker());

        $this->expectNotToPerformAssertions();
    }

    public function testAnEndpointThatThrowsIsSwallowed(): void
    {
        $endpoint = new class implements PyroscopeEndpoint {
            public function pushUrl(): ?string
            {
                throw new \RuntimeException('boom');
            }
        };
        $client = new PyroscopeClient(new MockHttpClient(), $endpoint);

        $client->push($this->profile(), ProfileLabels::forWorker());

        $this->expectNotToPerformAssertions();
    }

    private function profile(): CollapsedProfile
    {
        return new CollapsedProfile(self::STACKS, 4, 1000, 1700000000, 1700000005);
    }

    private function endpoint(?string $url): PyroscopeEndpoint
    {
        return new class ($url) implements PyroscopeEndpoint {
            public function __construct(private readonly ?string $url)
            {
            }

            public function pushUrl(): ?string
            {
                return $this->url;
            }
        };
    }
}
