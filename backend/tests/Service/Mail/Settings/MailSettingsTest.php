<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\MailFallback;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Proxy\ProxySettings;
use App\Tests\Support\SettingsRequests;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-import-type MailSettingsPayload from MailSettingsJson
 */
final class MailSettingsTest extends KernelTestCase
{
    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    /** @return MailSettingsPayload */
    private function view(): array
    {
        return MailSettingsJson::from($this->settings()->overview());
    }

    private function repository(): MailServerSettingsRepository
    {
        return self::getContainer()->get(MailServerSettingsRepository::class);
    }

    private function configureAProxy(): void
    {
        self::getContainer()->get(ProxySettings::class)->update(SettingsRequests::proxy(
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
        )->toUpdate());
    }

    public function testNoRowReportsDerivedEnabledFromTheFallback(): void
    {
        // The test env fallback is null://null, so mail derives to disabled.
        self::assertFalse($this->settings()->isSendingEnabled());
        self::assertFalse($this->view()['hasPassword']);
    }

    public function testUpdateStoresTheConnectionAndSealsThePassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            port: 587,
            username: 'postbox',
            encryption: MailEncryption::Starttls->value,
            fromAddress: 'noreply@reader.test',
            fromName: 'Reader',
            password: 'top-secret',
        )->toUpdate());

        $view = $this->view();
        self::assertTrue($view['enabled']);
        self::assertSame('smtp.relay.test', $view['host']);
        self::assertTrue($view['hasPassword']);
        self::assertArrayNotHasKey('password', $view);

        $resolved = $this->settings()->configuredTransport();
        self::assertNotNull($resolved);
        self::assertSame('top-secret', $resolved->password);
    }

    public function testANullPasswordKeepsTheStoredSecret(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'h', password: 'keep-me')->toUpdate());
        $this->settings()->update(SettingsRequests::mail(host: 'h2', password: null)->toUpdate());

        self::assertSame('keep-me', $this->settings()->configuredTransport()?->password);
    }

    public function testResetToEnvironmentDeletesTheSavedRow(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'smtp.relay.test', password: 'top-secret')->toUpdate());
        $before = $this->view();
        self::assertTrue($before['hasSavedConfig']);

        $this->settings()->resetToEnvironment();

        $view = $this->view();
        self::assertFalse($view['hasSavedConfig']);
        self::assertFalse($view['envFallbackConfigured']);
        self::assertNull($this->settings()->configuredTransport());
        self::assertFalse($this->settings()->isSendingEnabled());
    }

    public function testUpdateRejectsAnEnabledAuthenticatedRowWithNoPassword(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::passwordMissing());

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        )->toUpdate());
    }

    public function testUpdateRejectsEnablingWithNoHostWhileTheEnvFallbackIsNull(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::transportMissing());

        $this->settings()->update(SettingsRequests::mail(enabled: true, host: '')->toUpdate());
    }

    public function testUpdateAcceptsAnEnabledAuthenticatedRowThatKeepsAStoredPassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: false,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: 'top-secret',
        )->toUpdate());

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        )->toUpdate());

        $view = $this->view();
        self::assertTrue($view['enabled']);
        self::assertTrue($view['hasPassword']);
    }

    public function testUpdateAcceptsAnEnabledUnauthenticatedRelayWithNoUsername(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            username: null,
            password: null,
        )->toUpdate());

        self::assertTrue($this->view()['enabled']);
    }

    public function testASavedFromAddressWinsOverTheEnvIdentity(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'h',
            fromAddress: 'saved@reader.test',
            fromName: 'Saved',
            password: 'p',
        )->toUpdate());

        $identity = $this->settings()->identity();
        self::assertSame('saved@reader.test', $identity->address);
        self::assertSame('Saved', $identity->name);
    }

    public function testARowWithABlankFromAddressFallsBackToTheEnvIdentity(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'h', fromAddress: '', password: 'p')->toUpdate());

        self::assertSame(
            self::getContainer()->get(MailFallback::class)->identity()->address,
            $this->settings()->identity()->address,
        );
    }

    public function testADisabledAuthenticatedRowMayBeSavedWithoutAPassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: false,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        )->toUpdate());

        self::assertTrue($this->view()['hasSavedConfig']);
    }

    public function testAnEnabledRowWithNoHostAndAUsernameIsRefusedForTheMissingTransportNotThePassword(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::transportMissing());

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: '',
            username: 'postbox',
            password: null,
        )->toUpdate());
    }

    public function testRemovePasswordClearsTheStoredSecret(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.example.test',
            username: null,
            password: 'topsecret',
        )->toUpdate());
        $before = $this->view();
        self::assertTrue($before['hasPassword']);

        // A different host proves the remove-password update still applies the
        // connection edits carried in the same request, not only clears the secret.
        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.moved.test',
            username: null,
            removePassword: true,
        )->toUpdate());

        $view = $this->view();
        self::assertFalse($view['hasPassword']);
        self::assertSame('smtp.moved.test', $view['host']);
    }

    public function testRemovingThePasswordOfAnEnabledAuthenticatedRowIsRejected(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.example.test',
            username: 'alice',
            password: 'topsecret',
        )->toUpdate());

        $this->expectException(IncompleteMailConfigurationException::class);

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.example.test',
            username: 'alice',
            removePassword: true,
        )->toUpdate());
    }

    public function testUseProxyIsRejectedWhenNoEgressProxyIsConfigured(): void
    {
        $this->expectException(IncompleteMailConfigurationException::class);

        $this->settings()->update(SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true)->toUpdate());
    }

    public function testUseProxyIsPersistedWhenAProxyIsConfigured(): void
    {
        $this->configureAProxy();

        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.gmail.com',
            useProxy: true,
            password: 'app-pw',
        )->toUpdate());

        self::assertTrue($this->repository()->findSingleton()?->usesProxy());
        self::assertTrue($this->settings()->configuredTransport()?->useProxy);
    }
}
