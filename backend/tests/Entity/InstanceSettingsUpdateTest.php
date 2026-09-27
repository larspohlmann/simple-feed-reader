<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\InstanceSettingsUpdate;
use PHPUnit\Framework\TestCase;

/** No other test asserts the constructor default, so this one pins it. */
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
