<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\InstanceSettingsUpdate;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Settings\InstanceSettings;

/** The real InstanceSettings (final, so it cannot be doubled) behind a mail capability that always sends. */
trait RegistrationPolicies
{
    private function registrationPolicy(bool $confirm, bool $approve): RegistrationPolicy
    {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate($confirm, $approve, null, null, null));

        $mailSettings = $this->createStub(MailSendingSettingsInterface::class);
        $mailSettings->method('isSendingEnabled')->willReturn(true);

        return new RegistrationPolicy(new MailCapability($mailSettings), $settings);
    }
}
