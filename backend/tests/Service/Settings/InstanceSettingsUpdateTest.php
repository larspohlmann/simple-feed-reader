<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\InstanceSettingsUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one defaulted constructor parameter directly (#624 follow-up,
 * fix round 1): `infection:diff` caught this as an escaped mutant once the
 * addendum flipped the default to `false` — every existing caller either
 * predates the toggle (and so never mentions it) or is a test helper that
 * now sets it explicitly (PinsPasskeyRelyingParty, TogglesPasskeySignIn),
 * so nothing in the suite actually exercised the bare constructor default
 * until this test did.
 */
final class InstanceSettingsUpdateTest extends TestCase
{
    public function testPasskeySignInEnabledDefaultsToFalse(): void
    {
        $update = new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
        );

        self::assertFalse($update->passkeySignInEnabled);
    }
}
