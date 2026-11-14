<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\InstanceSettingsUpdate;
use App\Enum\UserStatus;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Settings\InstanceSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** InstanceSettings is final, so this boots the kernel and drives the real service instead of a double. */
final class RegistrationPolicyTest extends KernelTestCase
{
    private InstanceSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(InstanceSettings::class);
    }

    private function policy(bool $mailOn, bool $confirm, bool $approve): RegistrationPolicy
    {
        $this->settings->update(new InstanceSettingsUpdate($confirm, $approve, null, null, null));

        $mailSettings = $this->createStub(MailSendingSettingsInterface::class);
        $mailSettings->method('isSendingEnabled')->willReturn($mailOn);

        return new RegistrationPolicy(
            new MailCapability($mailSettings),
            $this->settings,
        );
    }

    public function testMailOffForcesEmailConfirmationOff(): void
    {
        $policy = $this->policy(mailOn: false, confirm: true, approve: true);
        self::assertFalse($policy->emailConfirmationRequired());
        self::assertFalse($policy->mailEnabled());
        self::assertTrue($policy->approvalRequired());
    }

    public function testStoredEmailConfirmationRequiredReflectsTheRawToggleEvenWithMailOff(): void
    {
        self::assertTrue($this->policy(mailOn: false, confirm: true, approve: true)->storedEmailConfirmationRequired());
    }

    public function testProspectiveStatusMatrix(): void
    {
        self::assertSame(
            UserStatus::PendingVerification,
            $this->policy(true, true, true)->prospectiveStatusForEmailSignup(),
        );
        self::assertSame(
            UserStatus::PendingVerification,
            $this->policy(true, true, false)->prospectiveStatusForEmailSignup(),
        );
        self::assertSame(
            UserStatus::PendingApproval,
            $this->policy(true, false, true)->prospectiveStatusForEmailSignup(),
        );
        self::assertSame(
            UserStatus::Active,
            $this->policy(true, false, false)->prospectiveStatusForEmailSignup(),
        );
        // Mail off collapses the confirm rows to their approval fallback.
        self::assertSame(
            UserStatus::PendingApproval,
            $this->policy(false, true, true)->prospectiveStatusForEmailSignup(),
        );
        self::assertSame(
            UserStatus::Active,
            $this->policy(false, true, false)->prospectiveStatusForEmailSignup(),
        );
    }
}
