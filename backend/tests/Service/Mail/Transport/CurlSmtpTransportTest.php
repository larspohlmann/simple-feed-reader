<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\CurlSmtpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class CurlSmtpTransportTest extends TestCase
{
    private function transport(): CurlSmtpTransport
    {
        // Port 1 is reserved and refuses instantly, so the send fails fast and
        // deterministically -- no external dependency, real curl path exercised.
        $resolved = new ResolvedMailTransport('smtp.invalid.test', 587, 'u', 'p', MailEncryption::Starttls, true);
        $proxy = new ProxyConfig(ProxyType::Socks5, '127.0.0.1', 1, null, null, true, true);

        return new CurlSmtpTransport($resolved, $proxy);
    }

    public function testItIsATransport(): void
    {
        self::assertInstanceOf(TransportInterface::class, $this->transport());
    }

    public function testAnUnreachableProxyRaisesATransportException(): void
    {
        $email = (new Email())->from('from@example.test')->to('to@example.test')->subject('x')->text('y');

        $this->expectException(TransportExceptionInterface::class);
        $this->transport()->send($email);
    }
}
