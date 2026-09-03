<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Dto\Admin\InstanceSettingsRequest;
use App\Exception\ValidationException;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;

/**
 * Guards the one field in InstanceSettingsRequest that a plain full-replace
 * PUT must not silently apply: the relying-party id is baked into every
 * stored credential at registration, so changing it orphans every enrolled
 * passkey. Lives here rather than in AdminSettingsController because
 * ThinControllerRule forbids validation or deletion logic in a controller.
 *
 * Compares the EFFECTIVE id — in effect now versus what the request would
 * leave in effect — never the raw stored passkeyRpId column, so clearing the
 * override back to the same-valued default is not a change. Only a CHANGE to
 * the effective id is guarded; a PUT that leaves it unchanged always
 * succeeds, or an admin could never edit other fields once a passkey exists.
 *
 * The confirmed delete is not in the same transaction as the settings write:
 * `UserPasskeyRepository::deleteAll()` is a bulk DQL DELETE that commits
 * immediately, while `InstanceSettings::update()` flushes later, from
 * `AdminSettingsController::update()`. A crash between the two would leave
 * credentials deleted but the id unchanged — accepted as-is; forcing both
 * into one transaction is more than this admin-only, rarely-hit path merits.
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
     * Named to say what it can do, not just what it checks: a confirmed
     * change deletes every enrolled passkey
     * (`UserPasskeyRepository::deleteAll()`) as part of this call.
     *
     * @throws ValidationException if passkeyRpId could not work as a
     *         relying-party id at all
     * @throws RelyingPartyChangeRequiresConfirmationException if the
     *         effective id is changing, credentials exist, and the request
     *         did not confirm
     */
    public function guardAndInvalidatePasskeysIfChanged(InstanceSettingsRequest $request): void
    {
        $this->assertUsableRelyingPartyId($request);

        $requestedEffectiveId = $this->effectiveId->derive(
            $request->passkeyRpId,
            $this->servingHost->get(),
        );
        if ($requestedEffectiveId === $this->relyingParty->id()) {
            return;
        }

        $enrolledCount = $this->passkeys->countAll();
        if (0 === $enrolledCount) {
            return;
        }

        if (!$request->invalidateExistingPasskeys) {
            throw new RelyingPartyChangeRequiresConfirmationException($enrolledCount);
        }

        $this->passkeys->deleteAll();
    }

    private function assertUsableRelyingPartyId(InstanceSettingsRequest $request): void
    {
        if (null === $request->passkeyRpId) {
            return;
        }

        if ($this->relyingPartyIdRule->isUsable($request->passkeyRpId)) {
            return;
        }

        throw new ValidationException([
            'passkeyRpId' => [
                'Must be a domain name, not an IP address or a bare top-level domain.',
            ],
        ]);
    }
}
