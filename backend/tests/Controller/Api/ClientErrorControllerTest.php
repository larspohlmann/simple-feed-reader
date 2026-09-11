<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * kernel.terminate — which LokiFlushListener hooks to drain LokiPushHandler's
 * buffer — runs synchronously inside KernelBrowser::request(), before control
 * returns here. So a report already reached and left the buffer by the time a
 * test method can inspect it; there is nothing left to read. The tests that
 * need to see what was recorded install a real LokiClient wired to a
 * MockHttpClient (installLokiCapture()) so the terminate-time flush lands in
 * an inspectable capture instead of a live network call, the same technique
 * LokiClientTest already uses for the client in isolation.
 */
final class ClientErrorControllerTest extends ApiTestCase
{
    /** @var list<array<string, mixed>> */
    private array $lokiPushes = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $pool = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    public function testAnonymousReportIsAccepted(): void
    {
        $client = $this->clientWithLokiCapture();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
    }

    public function testAcceptedReportIsPushedToLokiAsFrontend(): void
    {
        $client = $this->clientWithLokiCapture();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $labels = $this->firstPushedStream()['stream'];
        self::assertSame('frontend', $labels['source']);
        self::assertSame('client_errors', $labels['channel']);
    }

    public function testAuthenticatedReportTagsTheUserId(): void
    {
        $client = $this->clientWithLokiCapture();
        $user = $this->factory()->create('reporter@example.com');
        $this->authenticate($client, $user);

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $line = $this->firstPushedLine();
        self::assertSame($user->getId(), $line['context']['userId']);
    }

    public function testAnonymousReportCarriesNoUserId(): void
    {
        $client = $this->clientWithLokiCapture();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $line = $this->firstPushedLine();
        self::assertArrayNotHasKey('userId', $line['context']);
    }

    public function testRejectsTooManyItems(): void
    {
        $client = self::createClient();

        $this->post($client, array_fill(0, 11, $this->item()));

        $this->assertRejected($client, 422);
    }

    public function testRejectsAnItemWithABlankMessage(): void
    {
        $client = self::createClient();

        $this->post($client, [$this->item(message: '')]);

        $this->assertRejected($client, 422);
    }

    public function testRateLimitsAFlood(): void
    {
        $client = self::createClient();

        for ($i = 0; $i < 30; ++$i) {
            $this->post($client, [$this->item()]);
            self::assertResponseStatusCodeSame(202);
        }
        $this->post($client, [$this->item()]);

        $this->assertRejected($client, 429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    public function testScrubsThePiiBeforeRecording(): void
    {
        $client = $this->clientWithLokiCapture();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $line = $this->firstPushedLine();
        self::assertSame('https://app.example/reader', $line['context']['url']);
    }

    public function testContextCarriesEveryReportedField(): void
    {
        $client = $this->clientWithLokiCapture();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $line = $this->firstPushedLine();
        self::assertSame('Cannot read properties of undefined', $line['message']);
        self::assertSame('TypeError', $line['context']['kind']);
        self::assertSame('/reader', $line['context']['route']);
        self::assertSame('dev+local@', $line['context']['buildVersion']);
        self::assertSame('jest', $line['context']['userAgent']);
        self::assertSame('at Foo (main.js:1:2)', $line['context']['stack']);
        self::assertSame('2026-09-11T00:00:00.000Z', $line['context']['at']);
    }

    private function authenticate(KernelBrowser $client, User $user): void
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    /** @param list<array<string, mixed>> $errors */
    private function post(KernelBrowser $client, array $errors): void
    {
        $client->request(
            'POST',
            '/api/client-errors',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['errors' => $errors], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function item(string $message = 'Cannot read properties of undefined'): array
    {
        return [
            'message' => $message,
            'stack' => 'at Foo (main.js:1:2)',
            'kind' => 'TypeError',
            'url' => 'https://app.example/reader?token=secret#/entry/9',
            'route' => '/reader',
            'buildVersion' => 'dev+local@',
            'userAgent' => 'jest',
            'at' => '2026-09-11T00:00:00.000Z',
        ];
    }

    private function clientWithLokiCapture(): KernelBrowser
    {
        $client = self::createClient();
        $this->installLokiCapture();

        return $client;
    }

    /**
     * Replaces the real LokiClient with one wired to a MockHttpClient, so the
     * push that LokiFlushListener triggers on kernel.terminate lands in
     * {@see self::$lokiPushes} instead of attempting a real network call.
     * Must run before anything logs to the client_errors channel — the
     * container caches LokiPushHandler (and the LokiClient it was built
     * with) on first use, same as LokiClientTest's own MockHttpClient setup.
     */
    private function installLokiCapture(): void
    {
        $this->lokiPushes = [];
        $endpoint = new class implements LokiEndpoint {
            public function pushUrl(): string
            {
                return 'http://loki.test/loki/api/v1/push';
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, mixed> $options */
            $this->lokiPushes[] = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        self::getContainer()->set(LokiClient::class, new LokiClient($http, $endpoint));
    }

    /** @return array{stream: array<string, string>, values: list<array{0: string, 1: string}>} */
    private function firstPushedStream(): array
    {
        self::assertNotSame([], $this->lokiPushes, 'the report must reach Loki');
        /** @var array{body: string} $push */
        $push = $this->lokiPushes[0];
        $body = json_decode($push['body'], true, 512, \JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>} $body */
        return $body['streams'][0];
    }

    /** @return array{message: string, context: array<string, mixed>} */
    private function firstPushedLine(): array
    {
        $rawLine = $this->firstPushedStream()['values'][0][1];

        /** @var array{message: string, context: array<string, mixed>} */
        return json_decode($rawLine, true, 512, \JSON_THROW_ON_ERROR);
    }
}
