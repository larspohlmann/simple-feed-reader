<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\GuardedImageProbe;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageProbe\GuardedImageProbe;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use App\Tests\Support\PngImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class GuardedImageProbeTest extends TestCase
{
    use NoEgressProxy;

    private const string IMAGE_URL = 'https://img.example.com/a-768x432.jpg';
    private const int WIDTH = 768;

    /** @var list<array{method: string, headers: list<string>, timeout: mixed}> */
    private array $requests = [];

    /** @param list<MockResponse> $responses */
    private function probe(array $responses, string $address = '93.184.216.34'): GuardedImageProbe
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            /** @var list<string> $headers */
            $headers = $options['headers'];
            $this->requests[] = ['method' => $method, 'headers' => $headers, 'timeout' => $options['timeout']];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
        });
        $resolver = new class ($address) implements DnsResolverInterface {
            public function __construct(private readonly string $address)
            {
            }

            public function resolve(string $hostname): array
            {
                return [$this->address];
            }
        };

        return new GuardedImageProbe(
            FetchWiring::redirectFollower($client, $this->noEgressProxy(), new UrlGuard($resolver, new IpValidator())),
            'TestAgent/1.0',
        );
    }

    private static function image(
        int $status = Response::HTTP_OK,
        string $type = 'image/jpeg',
        string $body = '',
    ): MockResponse {
        return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['content-type' => [$type]]]);
    }

    public function testAnImageAnsweringHeadExists(): void
    {
        self::assertTrue($this->probe([self::image()])->exists(self::IMAGE_URL, self::WIDTH));
        self::assertSame('HEAD', $this->requests[0]['method']);
    }

    public function testAMissingImageDoesNotExist(): void
    {
        self::assertFalse($this->probe([self::image(Response::HTTP_NOT_FOUND)])->exists(self::IMAGE_URL, self::WIDTH));
    }

    public function testAnHtmlPageAnsweringOkIsNoImage(): void
    {
        self::assertFalse($this->probe([self::image(type: 'text/html')])->exists(self::IMAGE_URL, self::WIDTH));
    }

    /** @return iterable<string, array{int}> */
    public static function refusedHeadStatuses(): iterable
    {
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
        yield 'method not allowed' => [Response::HTTP_METHOD_NOT_ALLOWED];
        yield 'not implemented' => [Response::HTTP_NOT_IMPLEMENTED];
    }

    #[DataProvider('refusedHeadStatuses')]
    public function testARefusedHeadFallsBackToARangedGet(int $status): void
    {
        $probe = $this->probe([
            self::image($status),
            self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', PngImageFactory::bytes(768, 432)),
        ]);

        self::assertTrue($probe->exists(self::IMAGE_URL, self::WIDTH));
        self::assertSame('GET', $this->requests[1]['method']);
    }

    public function testAnotherErrorStatusDoesNotFallBack(): void
    {
        self::assertFalse($this->probe([self::image(Response::HTTP_GONE)])->exists(self::IMAGE_URL, self::WIDTH));
        self::assertCount(1, $this->requests);
    }

    public function testABlockedAddressIsUnreachable(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        try {
            $this->probe([self::image()], '10.0.0.1')->exists(self::IMAGE_URL, self::WIDTH);
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    public function testAHeadRedirectedElsewhereDoesNotExist(): void
    {
        $redirect = new MockResponse('', [
            'http_code' => Response::HTTP_FOUND,
            'response_headers' => ['location' => ['https://img.example.com/placeholder.jpg']],
        ]);

        self::assertFalse($this->probe([$redirect, self::image()])->exists(self::IMAGE_URL, self::WIDTH));
    }

    public function testAFallbackGetOfAnotherWidthDoesNotExist(): void
    {
        $probe = $this->probe([
            self::image(Response::HTTP_FORBIDDEN),
            self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', PngImageFactory::bytes(300, 169)),
        ]);

        self::assertFalse($probe->exists(self::IMAGE_URL, self::WIDTH));
    }

    public function testAFallbackGetOfBytesThatAreNoImageDoesNotExist(): void
    {
        $probe = $this->probe([
            self::image(Response::HTTP_FORBIDDEN),
            self::image(Response::HTTP_OK, 'image/jpeg', 'not an image'),
        ]);

        self::assertFalse($probe->exists(self::IMAGE_URL, self::WIDTH));
    }

    public function testAFallbackGetAnsweringNotFoundDoesNotExist(): void
    {
        $probe = $this->probe([self::image(Response::HTTP_FORBIDDEN), self::image(Response::HTTP_NOT_FOUND)]);

        self::assertFalse($probe->exists(self::IMAGE_URL, self::WIDTH));
    }

    public function testAFallbackGetFailingOnTheWireIsUnreachable(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $this->probe([self::image(Response::HTTP_FORBIDDEN), new MockResponse('', ['error' => 'reset'])])
            ->exists(self::IMAGE_URL, self::WIDTH);
    }

    public function testAMidBodyFailureIsUnavailableNotAnErrorException(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        $interrupted = new MockResponse(
            [PngImageFactory::bytes(264, 149), new TransportException('connection lost')],
            ['http_code' => Response::HTTP_OK, 'response_headers' => ['content-type' => ['image/png']]],
        );

        try {
            $this->probe([$interrupted])->measure(self::IMAGE_URL);
        } finally {
            restore_error_handler();
        }
    }

    public function testAnImageHeaderBeyondTheFirst64KibIsNotFound(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $bytes = str_repeat("\0", 65_536) . PngImageFactory::bytes(264, 149);

        $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);
    }

    public function testABodyBeyondTheDownloadCapIsUnavailable(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $bytes = PngImageFactory::bytes(264, 149) . str_repeat("\0", 5_000_001);

        $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);
    }

    public function testARangeIgnoringBodyBeyondHalfAMebibyteIsUnavailable(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $bytes = PngImageFactory::bytes(1200, 630) . str_repeat("\0", 600 * 1024);

        $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);
    }

    public function testARangeIgnoringBodyOfExactlyHalfAMebibyteStillMeasures(): void
    {
        $png = PngImageFactory::bytes(1200, 630);
        $bytes = $png . str_repeat("\0", 524_288 - \strlen($png));

        $dimensions = $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);

        self::assertSame(1200, $dimensions->width);
    }

    public function testUnavailabilityKeepsTheTransportCauseAndNoCode(): void
    {
        try {
            $this->probe([new MockResponse('', ['error' => 'reset'])])->measure(self::IMAGE_URL);
            self::fail('expected the image to be unavailable');
        } catch (GuardedImageUnavailableException $exception) {
            self::assertSame(0, $exception->getCode());
            self::assertInstanceOf(\Throwable::class, $exception->getPrevious());
            self::assertSame($exception->getPrevious()->getMessage(), $exception->getMessage());
        }
    }

    public function testARangeIgnoringBodyUnderHalfAMebibyteStillMeasures(): void
    {
        $bytes = PngImageFactory::bytes(1200, 630) . str_repeat("\0", 400 * 1024);

        $dimensions = $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);

        self::assertSame(1200, $dimensions->width);
    }

    public function testARedirectChainSharesOneTimeBudget(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $slow = static fn (): MockResponse => new MockResponse('', [
            'http_code' => Response::HTTP_FOUND,
            'response_headers' => ['location' => ['https://img.example.com/next.jpg']],
            'total_time' => 3.0,
        ]);

        $this->probe([$slow(), $slow(), self::image()])->exists(self::IMAGE_URL, self::WIDTH);
    }

    public function testMeasureAsksForTheFirst64KibOnly(): void
    {
        $png = PngImageFactory::bytes(264, 149);
        $this->probe([self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', $png)])->measure(self::IMAGE_URL);

        self::assertContains('Range: bytes=0-65535', $this->requests[0]['headers']);
    }

    public function testMeasureReadsThePartialHeader(): void
    {
        $png = PngImageFactory::bytes(264, 149);
        $dimensions = $this->probe([self::image(Response::HTTP_PARTIAL_CONTENT, 'image/png', $png)])
            ->measure(self::IMAGE_URL);

        self::assertSame([264, 149], [$dimensions->width, $dimensions->height]);
    }

    public function testMeasureAcceptsAServerIgnoringTheRange(): void
    {
        $bytes = PngImageFactory::bytes(1200, 630) . str_repeat("\0", 200_000);

        $dimensions = $this->probe([self::image(Response::HTTP_OK, 'image/png', $bytes)])->measure(self::IMAGE_URL);

        self::assertSame(1200, $dimensions->width);
    }

    public function testMeasureRejectsAMissingImage(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);
        $this->expectExceptionMessage('Image responded 404.');

        $this->probe([self::image(Response::HTTP_NOT_FOUND)])->measure(self::IMAGE_URL);
    }

    public function testMeasureRejectsBytesThatAreNoImage(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);
        $this->expectExceptionMessage('No image header in the first bytes of ' . self::IMAGE_URL);

        $this->probe([self::image(Response::HTTP_OK, 'image/jpeg', 'not an image')])->measure(self::IMAGE_URL);
    }

    public function testMeasureRejectsABlockedAddress(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $this->probe([self::image()], '10.0.0.1')->measure(self::IMAGE_URL);
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $this->probe([new MockResponse('', ['error' => 'connection reset'])])->exists(self::IMAGE_URL, self::WIDTH);
    }

    public function testMeasureRejectsATransportFailure(): void
    {
        $this->expectException(GuardedImageUnavailableException::class);

        $this->probe([new MockResponse('', ['error' => 'connection reset'])])->measure(self::IMAGE_URL);
    }

    public function testTheRequestCarriesAShortTimeout(): void
    {
        $this->probe([self::image()])->exists(self::IMAGE_URL, self::WIDTH);

        self::assertEquals(4, $this->requests[0]['timeout']);
    }

    public function testSendsTheOutboundUserAgentWithoutCompression(): void
    {
        $this->probe([self::image()])->exists(self::IMAGE_URL, self::WIDTH);

        self::assertContains('User-Agent: TestAgent/1.0', $this->requests[0]['headers']);
        self::assertContains('Accept-Encoding: identity', $this->requests[0]['headers']);
    }
}
