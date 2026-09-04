<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\EsmtpTransportBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class EsmtpTransportBuilderTest extends TestCase
{
    public function testTlsOpensAnImplicitTlsSocket(): void
    {
        $transport = $this->build(MailEncryption::Tls);

        self::assertTrue($this->socket($transport)->isTLS());
    }

    public function testStarttlsOpensAPlainSocketAndUpgradesAutomatically(): void
    {
        $transport = $this->build(MailEncryption::Starttls);

        self::assertFalse($this->socket($transport)->isTLS());
        self::assertTrue($transport->isAutoTls());
    }

    public function testNoneOpensAPlainSocketAndNeverUpgrades(): void
    {
        $transport = $this->build(MailEncryption::None);

        self::assertFalse($this->socket($transport)->isTLS());
        self::assertFalse($transport->isAutoTls());
    }

    public function testCredentialsAreAppliedOnlyWhenPresent(): void
    {
        $authenticated = EsmtpTransportBuilder::from(
            new ResolvedMailTransport('smtp.test', 2525, 'alice', 'hunter2', MailEncryption::Starttls),
            null,
            new NullLogger(),
        );
        $anonymous = $this->build(MailEncryption::Starttls);

        self::assertSame('alice', $authenticated->getUsername());
        self::assertSame('hunter2', $authenticated->getPassword());
        self::assertSame('', $anonymous->getUsername());
        self::assertSame('', $anonymous->getPassword());
    }

    public function testHostAndPortReachTheSocket(): void
    {
        $socket = $this->socket($this->build(MailEncryption::Starttls));

        self::assertSame('smtp.test', $socket->getHost());
        self::assertSame(2525, $socket->getPort());
    }

    private function build(MailEncryption $encryption): EsmtpTransport
    {
        return EsmtpTransportBuilder::from(
            new ResolvedMailTransport('smtp.test', 2525, null, null, $encryption),
            null,
            new NullLogger(),
        );
    }

    private function socket(EsmtpTransport $transport): SocketStream
    {
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }
}
