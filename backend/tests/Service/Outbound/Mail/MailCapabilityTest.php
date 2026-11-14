<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Mail;

use App\Service\Outbound\Mail\MailCapability;
use App\Service\Outbound\Mail\MailSendingSettings\MailSendingSettingsInterface;
use PHPUnit\Framework\TestCase;

final class MailCapabilityTest extends TestCase
{
    public function testItDelegatesToTheSettingsResolution(): void
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn(true);

        self::assertTrue((new MailCapability($settings))->isEnabled());
    }

    public function testItIsDisabledWhenSettingsResolveDisabled(): void
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn(false);

        self::assertFalse((new MailCapability($settings))->isEnabled());
    }
}
