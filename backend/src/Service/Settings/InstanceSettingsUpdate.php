<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * The full instance-setting row, replacing InstanceSettings::update()'s
 * previous three scalar parameters (#624). A value object rather than a wider
 * parameter list: CLAUDE.md caps a method at three parameters, and this row
 * grew a fourth and fifth field (passkeyRpId, passkeyRpName) the moment the
 * relying party became admin-configurable.
 */
final readonly class InstanceSettingsUpdate
{
    public function __construct(
        public bool $requireEmailConfirmation,
        public bool $requireApproval,
        public ?string $publicBaseUrl,
        public ?string $passkeyRpId,
        public ?string $passkeyRpName,
    ) {
    }
}
