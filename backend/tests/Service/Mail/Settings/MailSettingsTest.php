<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\MailSettings;
use App\Tests\Support\ConfiguresAProxy;
use App\Tests\Support\SettingsRequests;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-import-type MailSettingsPayload from MailSettingsJson
 */
final class MailSettingsTest extends KernelTestCase
{
    use ConfiguresAProxy;

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

    public function testNoRowReportsNoSavedConfigAndNoPassword(): void
    {
        $view = $this->view();

        self::assertFalse($view['hasSavedConfig']);
        self::assertFalse($view['hasPassword']);
    }

    public function testUpdateStoresTheConnectionAndHidesThePassword(): void
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
        self::assertNull($this->repository()->findSingleton());
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

    public function testRemovePasswordClearsTheStoredSecretAndStillAppliesTheConnection(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.example.test',
            username: null,
            password: 'topsecret',
        )->toUpdate());
        $before = $this->view();
        self::assertTrue($before['hasPassword']);

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
        $this->expectExceptionObject(IncompleteMailConfigurationException::proxyMissing());

        $this->settings()->update(SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true)->toUpdate());
    }

    public function testUseProxyIsPersistedWhenAProxyIsConfigured(): void
    {
        $this->configureAProxy();

        $this->settings()->update(
            SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true, password: 'app-pw')->toUpdate(),
        );

        self::assertTrue($this->repository()->findSingleton()?->usesProxy());
        self::assertTrue($this->view()['useProxy']);
    }
}
