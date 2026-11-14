<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailFallback;
use PHPUnit\Framework\TestCase;

final class MailFallbackTest extends TestCase
{
    public function testNullTransportIsNotReal(): void
    {
        $connection = (new MailFallback('null://null', 'noreply@example.com', 'Reader'))->connection();

        self::assertFalse($connection->enabled);
        self::assertSame('', $connection->host);
    }

    public function testAnSmtpDsnFillsTheFormDefaults(): void
    {
        $connection = (new MailFallback(
            'smtp://alice%40relay:pw@smtp.relay.test:2525',
            'noreply@example.com',
            'Reader',
        ))->connection();

        self::assertTrue($connection->enabled);
        self::assertSame('smtp.relay.test', $connection->host);
        self::assertSame(2525, $connection->port);
        self::assertSame('alice@relay', $connection->username);
        self::assertSame(MailEncryption::Starttls, $connection->encryption);
        self::assertSame('noreply@example.com', $connection->fromAddress);
        self::assertFalse($connection->useProxy);
    }

    public function testAnSmtpsDsnResolvesToImplicitTls(): void
    {
        $connection = (new MailFallback('smtps://smtp.relay.test', 'from@x.test', 'X'))->connection();

        self::assertSame(MailEncryption::Tls, $connection->encryption);
    }

    public function testASendmailDsnIsRealButNotSmtpParseable(): void
    {
        $connection = (new MailFallback(
            'sendmail://default?command=%2Fusr%2Fsbin%2Fsendmail%20-t%20-i',
            'from@x.test',
            'X',
        ))->connection();

        self::assertTrue($connection->enabled);
        self::assertSame('', $connection->host);
    }

    public function testABlankDsnIsNotEnabled(): void
    {
        self::assertFalse((new MailFallback('   ', 'from@x.test', 'X'))->connection()->enabled);
    }

    public function testAMalformedDsnIsEnabledButNotSmtpParseable(): void
    {
        $connection = (new MailFallback('smtp://', 'from@x.test', 'X'))->connection();

        self::assertTrue($connection->enabled);
        self::assertSame('', $connection->host);
        self::assertSame(587, $connection->port);
    }

    public function testAnSmtpDsnWithoutAPortFallsBackToTheSubmissionPort(): void
    {
        $connection = (new MailFallback('smtp://smtp.relay.test', 'from@x.test', 'X'))->connection();

        self::assertSame(587, $connection->port);
        self::assertNull($connection->username);
    }
}
