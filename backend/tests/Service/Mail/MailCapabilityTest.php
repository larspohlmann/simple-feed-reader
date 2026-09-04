<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailCapability;
use App\Service\Mail\Settings\MailSettings;
use PHPUnit\Framework\TestCase;

final class MailCapabilityTest extends TestCase
{
    public function testItDelegatesToTheSettingsResolution(): void
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        self::assertTrue((new MailCapability($settings))->isEnabled());
    }

    public function testItIsDisabledWhenSettingsResolveDisabled(): void
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn(false);

        self::assertFalse((new MailCapability($settings))->isEnabled());
    }
}
