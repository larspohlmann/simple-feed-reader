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
 * Only a CHANGE is guarded — a PUT that resends the id already stored always
 * succeeds, or the admin could never edit the other three fields once a
 * passkey exists.
 */
final readonly class RelyingPartyChange
{
    public function __construct(
        private InstanceSettings $settings,
        private PublicBaseUrl $publicBaseUrl,
        private UserPasskeyRepository $passkeys,
    ) {
    }

    /**
     * @throws ValidationException if passkeyRpId is not a registrable suffix
     *         of the host the server would actually use
     * @throws RelyingPartyChangeRequiresConfirmationException if the id is
     *         changing, credentials exist, and the request did not confirm
     */
    public function guard(InstanceSettingsRequest $request): void
    {
        $this->assertSuffixOfHost($request);

        if ($request->passkeyRpId === $this->settings->getPasskeyRpId()) {
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

        $host = parse_url($request->publicBaseUrl ?? $this->publicBaseUrl->get(), PHP_URL_HOST);

        if (\is_string($host) && $this->isSuffixOf($request->passkeyRpId, $host)) {
            return;
        }

        throw new ValidationException([
            'passkeyRpId' => [
                'Must be the host, or a registrable parent domain of the host, that the reader is served from.',
            ],
        ]);
    }

    private function isSuffixOf(string $relyingPartyId, string $host): bool
    {
        return $relyingPartyId === $host || str_ends_with($host, '.' . $relyingPartyId);
    }
}
