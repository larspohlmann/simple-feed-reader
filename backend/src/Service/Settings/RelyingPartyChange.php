<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Exception\ValidationException;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;

/**
 * A change of the EFFECTIVE relying-party id orphans every enrolled passkey, so it needs confirmation and then deletes
 * them all. The delete commits before the settings flush; a crash between the two is accepted.
 */
final readonly class RelyingPartyChange
{
    public function __construct(
        private PasskeyRelyingParty $relyingParty,
        private EffectivePasskeyRelyingPartyId $effectiveId,
        private UserPasskeyRepository $passkeys,
        private RelyingPartyIdRule $relyingPartyIdRule,
        private ServingHost $servingHost,
    ) {
    }

    /**
     * @throws ValidationException if the id could not work as a relying-party id at all
     * @throws RelyingPartyChangeRequiresConfirmationException if the effective id changes while passkeys exist
     *         and the change was not confirmed
     */
    public function guardAndInvalidatePasskeysIfChanged(RelyingPartyIdChoice $choice): void
    {
        $this->assertUsableRelyingPartyId($choice->passkeyRpId);

        $requestedEffectiveId = $this->effectiveId->derive($choice->passkeyRpId, $this->servingHost->get());
        if ($requestedEffectiveId === $this->relyingParty->id()) {
            return;
        }

        $enrolledCount = $this->passkeys->countAll();
        if (0 === $enrolledCount) {
            return;
        }

        if (!$choice->invalidateExistingPasskeys) {
            throw new RelyingPartyChangeRequiresConfirmationException($enrolledCount);
        }

        $this->passkeys->deleteAll();
    }

    private function assertUsableRelyingPartyId(?string $passkeyRpId): void
    {
        if (null === $passkeyRpId || $this->relyingPartyIdRule->isUsable($passkeyRpId)) {
            return;
        }

        throw new ValidationException([
            'passkeyRpId' => [
                'Must be a domain name, not an IP address or a bare top-level domain.',
            ],
        ]);
    }
}
