<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailSettingsTest extends KernelTestCase
{
    private function settings(): MailSettings
    {
        $settings = self::getContainer()->get(MailSettings::class);

        if (!$settings instanceof MailSettings) {
            throw new \LogicException('MailSettings service is misconfigured.');
        }

        return $settings;
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
}
