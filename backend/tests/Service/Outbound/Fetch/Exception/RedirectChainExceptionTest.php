<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Exception;

use App\Service\Outbound\Fetch\Exception\RedirectChainException;
use App\Service\Outbound\Fetch\Exception\SsrfBlockedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

final class RedirectChainExceptionTest extends TestCase
{
    public function testATransportFailureAnywhereInTheCausesCounts(): void
    {
        $failure = new RedirectChainException(
            'x',
            previous: new \RuntimeException('y', previous: new TransportException('timeout')),
        );

        self::assertTrue($failure->causedByTransportFailure());
    }

    public function testARefusalIsNoTransportFailure(): void
    {
        $refused = new RedirectChainException('x', previous: new SsrfBlockedException('private'));

        self::assertFalse($refused->causedByTransportFailure());
        self::assertFalse((new RedirectChainException('loop'))->causedByTransportFailure());
    }

    public function testAFailureAfterARedirectKeepsTheOriginalAsItsCause(): void
    {
        $original = new RedirectChainException('timed out', previous: new TransportException('timeout'));

        $afterRedirect = RedirectChainException::afterRedirect($original, 'https://news.example/a');

        self::assertSame($original, $afterRedirect->getPrevious());
        self::assertSame('https://news.example/a', $afterRedirect->hopUrl);
        self::assertTrue($afterRedirect->causedByTransportFailure());
    }
}
