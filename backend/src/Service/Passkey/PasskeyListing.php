<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Http\PasskeyJson;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\PasskeyRelyingParty;

/**
 * Builds the passkey listing body for one account (#727) — the same body the
 * listing and the enrolment 201 return, so the two cannot drift.
 *
 * @phpstan-import-type PasskeyListingBody from PasskeyJson
 */
final readonly class PasskeyListing
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyRelyingParty $relyingParty,
    ) {
    }

    /**
     * @return PasskeyListingBody
     */
    public function forUser(User $user): array
    {
        return PasskeyJson::listing($this->relyingParty->id(), $this->passkeys->findForUser($user));
    }
}
