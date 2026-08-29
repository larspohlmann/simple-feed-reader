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
 * stored credential at registration time, so changing it orphans every
 * enrolled passkey. This lives here, not in AdminSettingsController, because
 * ThinControllerRule forbids a controller from carrying validation or
 * deletion logic itself.
 *
 * The comparison is on the EFFECTIVE id — PasskeyRelyingParty::id() right
 * now, versus EffectivePasskeyRelyingPartyId::derive() applied to the
 * incoming request — never on the raw stored passkeyRpId column. publicBaseUrl
 * drives the effective id whenever passkeyRpId is left null (it feeds every
 * outgoing email link too, so admins do edit it), so an admin who edits
 * ONLY publicBaseUrl can silently move the id a stored-value comparison would
 * never notice. Do not "simplify" this back to comparing the stored column —
 * that reopens exactly the hole this guard exists to close.
 *
 * Only a CHANGE to the effective id is guarded — a PUT that leaves it exactly
 * where it was always succeeds, or the admin could never edit the other
 * fields once a passkey exists.
 */
final readonly class RelyingPartyChange
{
    public function __construct(
        private PasskeyRelyingParty $relyingParty,
        private PublicBaseUrl $publicBaseUrl,
        private EffectivePasskeyRelyingPartyId $effectiveId,
        private UserPasskeyRepository $passkeys,
    ) {
    }

    /**
     * @throws ValidationException if passkeyRpId is not a registrable suffix
     *         of the host the server would actually use
     * @throws RelyingPartyChangeRequiresConfirmationException if the
     *         effective id is changing, credentials exist, and the request
     *         did not confirm
     */
    public function guard(InstanceSettingsRequest $request): void
    {
        $this->assertSuffixOfHost($request);

        $requestedEffectiveId = $this->effectiveId->derive(
            $request->passkeyRpId,
            $this->resolvedPublicBaseUrl($request),
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

    private function assertSuffixOfHost(InstanceSettingsRequest $request): void
    {
        if (null === $request->passkeyRpId) {
            return;
        }

        $host = parse_url($this->resolvedPublicBaseUrl($request), PHP_URL_HOST);

        if (\is_string($host) && $this->isSuffixOf($request->passkeyRpId, $host)) {
            return;
        }

        throw new ValidationException([
            'passkeyRpId' => [
                'Must be the host, or a registrable parent domain of the host, that the reader is served from.',
            ],
        ]);
    }

    /**
     * The publicBaseUrl this request would leave in effect: what it sends, or
     * — since it is a full-replace payload — the same fallback PublicBaseUrl
     * would resolve to today if the request sends none.
     */
    private function resolvedPublicBaseUrl(InstanceSettingsRequest $request): string
    {
        return $request->publicBaseUrl ?? $this->publicBaseUrl->get();
    }

    private function isSuffixOf(string $relyingPartyId, string $host): bool
    {
        return $relyingPartyId === $host || str_ends_with($host, '.' . $relyingPartyId);
    }
}
