<?php

declare(strict_types=1);

namespace App\Service\Passkey\Factory;

use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;
use Random\RandomException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Login options: discoverable credentials only (no e-mail, no allow list, identical for every caller) and user
 * verification required, the passkey being the account's only factor. AssertionVerifier rebuilds them through
 * optionsFor(), so the two cannot drift. Why: docs/security.md#passkey-ceremonies
 */
final readonly class AssertionOptionsFactory
{
    private const int CHALLENGE_LENGTH_BYTES = 32;

    public function __construct(
        private PasskeyCeremony $ceremony,
        private PasskeyChallengeStore $challengeStore,
        private PasskeyRelyingPartyInterface $relyingParty,
    ) {
    }

    /**
     * @return array{options: array<string, mixed>, handle: string}
     *
     * @throws RandomException
     */
    public function create(): array
    {
        $challenge = random_bytes(self::CHALLENGE_LENGTH_BYTES);

        return [
            'options' => $this->ceremony->encode($this->optionsFor($challenge)),
            'handle' => $this->challengeStore->issue($challenge, userId: null, userHandle: null),
        ];
    }

    public function optionsFor(string $challenge): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $this->relyingParty->id(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );
    }
}
