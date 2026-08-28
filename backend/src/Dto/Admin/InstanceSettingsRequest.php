<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * This is a full-replace payload, not a partial patch. `#[MapRequestPayload]`
 * fills any field missing from the request body with the constructor default
 * (both booleans default to `true`, the URL to `null`), so a `PUT` that sends
 * only one field silently resets the others. Clients must always send every
 * field together.
 *
 * `publicBaseUrl` is null when the admin clears it — the client sends `null`,
 * not an empty string — which restores the APP_FRONTEND_URL fallback.
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
    ) {
    }
}
