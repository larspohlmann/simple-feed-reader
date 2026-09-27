<?php

declare(strict_types=1);

namespace App\Entity;

/** The whole instance-setting row, written at once. */
final readonly class InstanceSettingsUpdate
{
    public function __construct(
        public bool $requireEmailConfirmation,
        public bool $requireApproval,
        public ?string $publicBaseUrl,
        public ?string $passkeyRpId,
        public ?string $passkeyRpName,
        // Matches InstanceSetting's column default: a test that needs working passkeys must pass true.
        public bool $passkeySignInEnabled = false,
    ) {
    }
}
