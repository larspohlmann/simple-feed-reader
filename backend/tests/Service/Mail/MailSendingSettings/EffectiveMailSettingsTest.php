<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\MailSendingSettings;

use App\Enum\MailEncryption;
use App\Service\Mail\MailSendingSettings\EffectiveMailSettings;
use App\Service\Mail\Settings\MailFallback;
use App\Service\Mail\Settings\MailSettings;
use App\Tests\Support\ConfiguresAProxy;
use App\Tests\Support\SettingsRequests;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EffectiveMailSettingsTest extends KernelTestCase
{
    use ConfiguresAProxy;

    private function effective(): EffectiveMailSettings
    {
        return self::getContainer()->get(EffectiveMailSettings::class);
    }

    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    public function testWithNoRowEverythingFollowsTheEnvFallback(): void
    {
        // The test env fallback is null://null, so mail derives to disabled.
        $effective = $this->effective();

        self::assertFalse($effective->isSendingEnabled());
        self::assertFalse($effective->hasEnvFallback());
        self::assertSame('null://null', $effective->activeTransportDsnFallback());
        self::assertNull($effective->configuredTransport());
    }

    public function testAnEnabledRowTurnsSendingOn(): void
    {
        $this->settings()->update(
            SettingsRequests::mail(enabled: true, host: 'smtp.relay.test', password: 'p')->toUpdate(),
        );

        self::assertTrue($this->effective()->isSendingEnabled());
    }

    public function testTheConfiguredTransportCarriesEveryFieldAndTheOpenedPassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.relay.test',
            port: 2525,
            username: 'postbox',
            encryption: MailEncryption::Tls->value,
            password: 'top-secret',
        )->toUpdate());

        $resolved = $this->effective()->configuredTransport();

        self::assertNotNull($resolved);
        self::assertSame('smtp.relay.test', $resolved->host);
        self::assertSame(2525, $resolved->port);
        self::assertSame('postbox', $resolved->username);
        self::assertSame('top-secret', $resolved->password);
        self::assertSame(MailEncryption::Tls, $resolved->encryption);
        self::assertFalse($resolved->useProxy);
    }

    public function testANullPasswordKeepsTheStoredSecret(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'h', password: 'keep-me')->toUpdate());
        $this->settings()->update(SettingsRequests::mail(host: 'h2', password: null)->toUpdate());

        self::assertSame('keep-me', $this->effective()->configuredTransport()?->password);
    }

    public function testASavedFromAddressWinsOverTheEnvIdentity(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'h',
            fromAddress: 'saved@reader.test',
            fromName: 'Saved',
            password: 'p',
        )->toUpdate());

        $identity = $this->effective()->identity();

        self::assertSame('saved@reader.test', $identity->address);
        self::assertSame('Saved', $identity->name);
    }

    public function testARowWithABlankFromAddressFallsBackToTheEnvIdentity(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'h', fromAddress: '', password: 'p')->toUpdate());

        self::assertSame(
            self::getContainer()->get(MailFallback::class)->identity()->address,
            $this->effective()->identity()->address,
        );
    }

    public function testUseProxyReachesTheResolvedTransport(): void
    {
        $this->configureAProxy();

        $this->settings()->update(
            SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true, password: 'app-pw')->toUpdate(),
        );

        self::assertTrue($this->effective()->configuredTransport()?->useProxy);
    }

    public function testAfterAResetTheEnvFallbackDecidesAgain(): void
    {
        $this->settings()->update(
            SettingsRequests::mail(enabled: true, host: 'smtp.relay.test', password: 'p')->toUpdate(),
        );

        $this->settings()->resetToEnvironment();

        self::assertFalse($this->effective()->isSendingEnabled());
        self::assertNull($this->effective()->configuredTransport());
    }
}
