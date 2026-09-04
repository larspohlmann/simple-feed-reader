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
        $context = (new MailFallback('null://null', 'noreply@example.com', 'Reader'))->context();

        self::assertFalse($context->isReal);
        self::assertSame('', $context->host);
    }

    public function testAnSmtpDsnFillsTheFormDefaults(): void
    {
        $context = (new MailFallback(
            'smtp://alice%40relay:pw@smtp.relay.test:2525',
            'noreply@example.com',
            'Reader',
        ))->context();

        self::assertTrue($context->isReal);
        self::assertSame('smtp.relay.test', $context->host);
        self::assertSame(2525, $context->port);
        self::assertSame('alice@relay', $context->username);
        self::assertSame(MailEncryption::Starttls, $context->encryption);
        self::assertSame('noreply@example.com', $context->fromAddress);
    }

    public function testAnSmtpsDsnResolvesToImplicitTls(): void
    {
        $context = (new MailFallback('smtps://smtp.relay.test', 'from@x.test', 'X'))->context();

        self::assertSame(MailEncryption::Tls, $context->encryption);
    }

    public function testASendmailDsnIsRealButNotSmtpParseable(): void
    {
        $context = (new MailFallback(
            'sendmail://default?command=%2Fusr%2Fsbin%2Fsendmail%20-t%20-i',
            'from@x.test',
            'X',
        ))->context();

        self::assertTrue($context->isReal);
        self::assertSame('', $context->host);
    }
}
