<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\MailFallback;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailSettingsTest extends KernelTestCase
{
    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    public function testNoRowReportsDerivedEnabledFromTheFallback(): void
    {
        // The test env fallback is null://null, so mail derives to disabled.
        self::assertFalse($this->settings()->isSendingEnabled());
        self::assertFalse($this->settings()->view()['hasPassword']);
    }

    public function testUpdateStoresTheConnectionAndSealsThePassword(): void
    {
        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: 'smtp.relay.test',
            port: 587,
            username: 'postbox',
            encryption: MailEncryption::Starttls->value,
            fromAddress: 'noreply@reader.test',
            fromName: 'Reader',
            password: 'top-secret',
        ));

        $view = $this->settings()->view();
        self::assertTrue($view['enabled']);
        self::assertSame('smtp.relay.test', $view['host']);
        self::assertTrue($view['hasPassword']);
        self::assertSame('cret', $view['passwordHint']);
        self::assertArrayNotHasKey('password', $view);

        $resolved = $this->settings()->configuredTransport();
        self::assertNotNull($resolved);
        self::assertSame('top-secret', $resolved->password);
    }

    public function testANullPasswordKeepsTheStoredSecret(): void
    {
        $this->settings()->update(new MailSettingsRequest(host: 'h', password: 'keep-me'));
        $this->settings()->update(new MailSettingsRequest(host: 'h2', password: null));

        self::assertSame('keep-me', $this->settings()->configuredTransport()?->password);
    }

    public function testResetToEnvironmentDeletesTheSavedRow(): void
    {
        $this->settings()->update(new MailSettingsRequest(host: 'smtp.relay.test', password: 'top-secret'));
        self::assertTrue($this->settings()->view()['hasSavedConfig']);

        $this->settings()->resetToEnvironment();

        $view = $this->settings()->view();
        self::assertFalse($view['hasSavedConfig']);
        self::assertFalse($view['envFallbackConfigured']);
        self::assertNull($this->settings()->configuredTransport());
        self::assertFalse($this->settings()->isSendingEnabled());
    }

    public function testUpdateRejectsAnEnabledAuthenticatedRowWithNoPassword(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::passwordMissing());

        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        ));
    }

    public function testUpdateRejectsEnablingWithNoHostWhileTheEnvFallbackIsNull(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::transportMissing());

        $this->settings()->update(new MailSettingsRequest(enabled: true, host: ''));
    }

    public function testUpdateAcceptsAnEnabledAuthenticatedRowThatKeepsAStoredPassword(): void
    {
        $this->settings()->update(new MailSettingsRequest(
            enabled: false,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: 'top-secret',
        ));

        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        ));

        self::assertTrue($this->settings()->view()['enabled']);
        self::assertTrue($this->settings()->view()['hasPassword']);
    }

    public function testUpdateAcceptsAnEnabledUnauthenticatedRelayWithNoUsername(): void
    {
        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: 'smtp.relay.test',
            username: null,
            password: null,
        ));

        self::assertTrue($this->settings()->view()['enabled']);
    }

    public function testTheHintIsTheLastFourCharactersEvenWhenTheyAreMultibyte(): void
    {
        $this->settings()->update(new MailSettingsRequest(host: 'h', password: 'pässwört'));

        self::assertSame('wört', $this->settings()->view()['passwordHint']);
    }

    public function testASavedFromAddressWinsOverTheEnvIdentity(): void
    {
        $this->settings()->update(new MailSettingsRequest(
            host: 'h',
            fromAddress: 'saved@reader.test',
            fromName: 'Saved',
            password: 'p',
        ));

        $identity = $this->settings()->identity();
        self::assertSame('saved@reader.test', $identity->address);
        self::assertSame('Saved', $identity->name);
    }

    public function testARowWithABlankFromAddressFallsBackToTheEnvIdentity(): void
    {
        $this->settings()->update(new MailSettingsRequest(host: 'h', fromAddress: '', password: 'p'));

        self::assertSame(
            self::getContainer()->get(MailFallback::class)->identity()->address,
            $this->settings()->identity()->address,
        );
    }

    public function testADisabledAuthenticatedRowMayBeSavedWithoutAPassword(): void
    {
        $this->settings()->update(new MailSettingsRequest(
            enabled: false,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        ));

        self::assertTrue($this->settings()->view()['hasSavedConfig']);
    }

    public function testAnEnabledRowWithNoHostAndAUsernameIsRefusedForTheMissingTransportNotThePassword(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::transportMissing());

        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: '',
            username: 'postbox',
            password: null,
        ));
    }
}
