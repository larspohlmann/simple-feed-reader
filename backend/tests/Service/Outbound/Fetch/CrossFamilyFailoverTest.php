<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch;

use App\Service\Outbound\Fetch\CrossFamilyFailover;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CrossFamilyFailoverTest extends TestCase
{
    private CrossFamilyFailover $failover;

    protected function setUp(): void
    {
        $this->failover = new CrossFamilyFailover();
    }

    public function testAConnectionResetWarrantsAnotherFamily(): void
    {
        self::assertTrue($this->failover->isWarranted(new TransportException('Connection reset by peer')));
    }

    public function testATimeoutDoesNotWarrantAnotherFamily(): void
    {
        // A timeout means the family answered the connect but is slow, not that
        // the route is dead; re-driving every family would only multiply the wait.
        self::assertFalse($this->failover->isWarranted(new TimeoutException('Idle timeout reached')));
    }

    public function testAnAbsentTransportErrorWarrantsNothing(): void
    {
        self::assertFalse($this->failover->isWarranted(null));
    }

    public function testAClientOrServerErrorStatusWarrantsAnotherFamily(): void
    {
        // A 403 from taz over IPv6 while IPv4 serves 200 is an address-family
        // block, not a genuine refusal — the other family is worth a try.
        self::assertTrue($this->failover->isRetryableStatus(400));
        self::assertTrue($this->failover->isRetryableStatus(403));
        self::assertTrue($this->failover->isRetryableStatus(503));
    }

    public function testAMissingResourceStatusDoesNotWarrantAnotherFamily(): void
    {
        self::assertFalse($this->failover->isRetryableStatus(404));
        self::assertFalse($this->failover->isRetryableStatus(410));
    }

    public function testASuccessOrRedirectStatusDoesNotWarrantAnotherFamily(): void
    {
        // 2xx and 304 are answers; 3xx is a redirect the caller follows. None is
        // a failure to route around.
        self::assertFalse($this->failover->isRetryableStatus(200));
        self::assertFalse($this->failover->isRetryableStatus(304));
        self::assertFalse($this->failover->isRetryableStatus(301));
    }

    public function testTooManyRequestsWarrantsNoOtherRoute(): void
    {
        self::assertFalse($this->failover->isRetryableStatus(Response::HTTP_TOO_MANY_REQUESTS));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function repeatableMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'lowercase head' => ['head'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function writesWithAnswers(): iterable
    {
        foreach (['POST', 'put', 'DELETE', 'PATCH'] as $method) {
            yield $method . ' bad gateway' => [$method, Response::HTTP_BAD_GATEWAY];
            yield $method . ' forbidden' => [$method, Response::HTTP_FORBIDDEN];
        }
    }

    #[DataProvider('repeatableMethods')]
    public function testARepeatableMethodMayBeAskedAgainAfterAnErrorStatus(string $method): void
    {
        self::assertTrue($this->failover->allowsRetryAfterStatus($method, Response::HTTP_BAD_GATEWAY));
    }

    #[DataProvider('writesWithAnswers')]
    public function testAWriteIsNeverAskedAgainAfterAnAnswer(string $method, int $statusCode): void
    {
        self::assertFalse($this->failover->allowsRetryAfterStatus($method, $statusCode));
    }

    public function testAWriteThatNeverLeftMayBeSentAgain(): void
    {
        [$failed, $error] = $this->failedResponse(['pretransfer_time' => 0.0]);

        self::assertTrue($this->failover->allowsRetryAfterFailure('POST', $failed, $error));
    }

    public function testAWriteThatMayHaveLeftIsNotSentAgain(): void
    {
        [$failed, $error] = $this->failedResponse(['pretransfer_time' => 0.042]);

        self::assertFalse($this->failover->allowsRetryAfterFailure('POST', $failed, $error));
    }

    public function testAWriteWithoutPretransferTimeIsNotSentAgain(): void
    {
        [$failed, $error] = $this->failedResponse([]);

        self::assertFalse($this->failover->allowsRetryAfterFailure('POST', $failed, $error));
    }

    public function testATimedOutWriteIsNotSentAgain(): void
    {
        $failed = new MockResponse('', ['pretransfer_time' => 0.0]);

        self::assertFalse(
            $this->failover->allowsRetryAfterFailure('POST', $failed, new TimeoutException('Idle timeout reached')),
        );
    }

    public function testARepeatableMethodIsSentAgainAfterAResetWhateverWasSent(): void
    {
        [$failed, $error] = $this->failedResponse(['pretransfer_time' => 0.042]);

        self::assertTrue($this->failover->allowsRetryAfterFailure('get', $failed, $error));
    }

    /**
     * @param array<string, mixed> $responseInfo
     *
     * @return array{ResponseInterface, TransportExceptionInterface}
     */
    private function failedResponse(array $responseInfo): array
    {
        $response = (new MockHttpClient(
            new MockResponse('', ['error' => 'Connection reset by peer'] + $responseInfo),
        ))->request('POST', 'https://example.com/');

        try {
            $response->getStatusCode();
        } catch (TransportExceptionInterface $transportError) {
            return [$response, $transportError];
        }

        self::fail('The mock response should have failed.');
    }
}
