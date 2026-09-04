<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\CurlSmtpOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

final class CurlSmtpOptionsTest extends TestCase
{
    private function envelope(): Envelope
    {
        return new Envelope(new Address('from@example.test'), [new Address('to@example.test')]);
    }

    private function proxy(): ProxyConfig
    {
        return new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true);
    }

    public function testImplicitTlsUsesSmtpsScheme(): void
    {
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 465, 'u', 'p', MailEncryption::Tls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('smtps://smtp.gmail.com:465', $options[\CURLOPT_URL]);
    }

    public function testStarttlsRequiresTlsUpgradeOverPlainScheme(): void
    {
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('smtp://smtp.gmail.com:587', $options[\CURLOPT_URL]);
        self::assertSame(\CURLUSESSL_ALL, $options[\CURLOPT_USE_SSL]);
    }

    public function testEnvelopeAddressesAreBracketed(): void
    {
        $resolved = new ResolvedMailTransport('h', 587, null, null, MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('<from@example.test>', $options[\CURLOPT_MAIL_FROM]);
        self::assertSame(['<to@example.test>'], $options[\CURLOPT_MAIL_RCPT]);
    }

    public function testProxyDsnIsPassedThrough(): void
    {
        $resolved = new ResolvedMailTransport('h', 587, null, null, MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertSame('socks5h://proxy.example:1080', $options[\CURLOPT_PROXY]);
    }

    public function testCredentialsAreOmittedWhenAbsent(): void
    {
        $resolved = new ResolvedMailTransport('h', 587, null, null, MailEncryption::Starttls, true);
        $options = CurlSmtpOptions::for($resolved, $this->proxy(), $this->envelope());
        self::assertArrayNotHasKey(\CURLOPT_USERNAME, $options);
    }
}
