<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * This is a full-replace payload, not a partial patch. `#[MapRequestPayload]`
 * fills any field missing from the request body with the constructor default
 * (both booleans default to `true`), so a `PUT` that sends only one field
 * silently resets the other one to "on". Clients must always send both
 * fields together.
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
    ) {
    }
}
