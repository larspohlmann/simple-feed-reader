<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport\Exception;

use App\Service\Mail\Transport\Exception\ProxiedSmtpSendException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class ProxiedSmtpSendExceptionTest extends TestCase
{
    public function testItExplainsASocksReplyCodeInsteadOfShowingTheRawByte(): void
    {
        $exception = ProxiedSmtpSendException::fromCurlError(
            'cannot complete SOCKS5 connection to smtp.gmail.com. (4)',
        );

        self::assertInstanceOf(TransportExceptionInterface::class, $exception);
        self::assertStringContainsString('Proxied SMTP send failed:', $exception->getMessage());
        self::assertStringContainsString('does not resolve host names', $exception->getMessage());
    }

    public function testItLeavesAnUnrelatedCurlMessageIntact(): void
    {
        $exception = ProxiedSmtpSendException::fromCurlError('Failed to connect to proxy port 1080');

        self::assertStringContainsString('Failed to connect to proxy port 1080', $exception->getMessage());
    }
}
