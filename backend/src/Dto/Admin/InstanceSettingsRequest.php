<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Settings\InstanceSettingsUpdate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * This is a full-replace payload, not a partial patch. `#[MapRequestPayload]`
 * fills any field missing from the request body with the constructor default
 * (every boolean, including `passkeySignInEnabled`, defaults to `true`, the
 * URL and both relying-party fields to `null`), so a `PUT` that sends only one
 * field silently resets the others. Clients must always send every field
 * together.
 *
 * `publicBaseUrl` is null when the admin clears it — the client sends `null`,
 * not an empty string — which restores the APP_FRONTEND_URL fallback.
 * `passkeyRpId` and `passkeyRpName` behave the same way, restoring the
 * derived host and the "Simple Feed Reader" default respectively.
 *
 * `passkeySignInEnabled` is the instance-wide switch
 * {@see \App\Service\Passkey\PasskeySignInAvailability} reads alongside the
 * relying-party validity check — turning it off refuses every passkey
 * endpoint (registration, listing, login) regardless of configuration,
 * without touching a single stored credential.
 *
 * `invalidateExistingPasskeys` is NOT a setting — it is not part of
 * InstanceSettingsUpdate and is never persisted. It is a one-shot command
 * modifier, read only by RelyingPartyChange, that confirms a passkeyRpId
 * change the admin already saw refused with a 409. It defaults to `false`.
 */
final readonly class InstanceSettingsRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireEmailConfirmation = true,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireApproval = true,
        #[Assert\Url(requireTld: false)]
        #[Assert\Length(max: 255)]
        public ?string $publicBaseUrl = null,
        #[Assert\Length(max: 255)]
        public ?string $passkeyRpId = null,
        #[Assert\Length(max: 100)]
        public ?string $passkeyRpName = null,
        public bool $invalidateExistingPasskeys = false,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $passkeySignInEnabled = true,
    ) {
    }

    public function toUpdate(): InstanceSettingsUpdate
    {
        return new InstanceSettingsUpdate(
            requireEmailConfirmation: $this->requireEmailConfirmation,
            requireApproval: $this->requireApproval,
            publicBaseUrl: $this->publicBaseUrl,
            passkeyRpId: $this->passkeyRpId,
            passkeyRpName: $this->passkeyRpName,
            passkeySignInEnabled: $this->passkeySignInEnabled,
        );
    }
}
