<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Exception;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

final class FetchExceptionTest extends TestCase
{
    public function testAnOversizedBodyIsUnwrappedFromTheClientsOwnWrapper(): void
    {
        $tooLarge = new ResponseTooLargeException('too large');

        $exception = FetchException::from('https://feeds.example/rss', new \RuntimeException('wrapped', 0, $tooLarge));

        self::assertSame($tooLarge, $exception);
    }

    public function testATransportFailureKeepsTheUrlAndTheCause(): void
    {
        $cause = new TransportException('Could not resolve host: feeds.example');

        $exception = FetchException::from('https://feeds.example/rss', $cause);

        self::assertInstanceOf(FeedUnreachableException::class, $exception);
        self::assertStringContainsString('https://feeds.example/rss', $exception->getMessage());
        self::assertStringContainsString('Could not resolve host: feeds.example', $exception->getMessage());
        self::assertSame($cause, $exception->getPrevious());
    }

    /**
     * A proxied sweep that fails every feed must say why in words. Without this
     * the refresh report repeated curl's raw RFC 1928 reply byte once per feed.
     */
    public function testASocks5HandshakeRefusalIsExplained(): void
    {
        $exception = FetchException::from(
            'https://feeds.example/rss',
            new TransportException('cannot complete SOCKS5 connection to feeds.example. (4)'),
        );

        self::assertStringContainsString('does not resolve host names', $exception->getMessage());
    }
}
