<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Enum\UserStatus;
use App\Service\Auth\RegistrationPolicy;
use App\Service\Mail\MailCapability;
use App\Service\Settings\InstanceSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Both collaborators are `final readonly`/`final`, including the repository
 * behind InstanceSettings, so PHPUnit cannot double them. We boot the kernel
 * and drive the real InstanceSettings service instead — the same workaround
 * InstanceSettingsTest uses for this exact final-class constraint.
 */
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
        $this->settings->update($confirm, $approve, null);

        return new RegistrationPolicy(
            new MailCapability($mailOn ? '' : '1'),
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
