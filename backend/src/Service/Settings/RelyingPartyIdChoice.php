<?php

declare(strict_types=1);

namespace App\Service\Settings;

/** The relying-party id an admin asks for (null: the derived default), and whether they confirmed losing passkeys. */
final readonly class RelyingPartyIdChoice
{
    public function __construct(
        public ?string $passkeyRpId,
        public bool $invalidateExistingPasskeys,
    ) {
    }
}
