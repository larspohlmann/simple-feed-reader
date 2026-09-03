<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Settings\PasskeyRelyingParty;
use Random\RandomException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Builds options for a WebAuthn login ("assertion") ceremony (#624):
 * discoverable-credential only, so `allowCredentials` is always empty and
 * `create()` takes no e-mail or user id — the server does not know who is
 * asking until the authenticator answers.
 *
 * `userVerification` is REQUIRED, not preferred: the passkey is this account's
 * sole authentication factor, so skipping verification would skip the only check
 * between "the device is unlocked" and "this account is logged in".
 *
 * No enumeration: `create()` takes no parameter that varies with whether an
 * account exists, so response shape and cost are identical for every caller.
 * The stored challenge carries a null user id and handle for the same reason
 * — see PasskeyChallenge's docblock.
 *
 * Unlike RegistrationOptionsFactory, this class never touches
 * PasskeyCeremony::request() — that's AssertionVerifier's job, verifying the
 * response — and needs no `rp.name`-after-serialisation stitch:
 * `PublicKeyCredentialRequestOptions::$rpId` is a plain, un-deprecated
 * constructor property.
 *
 * `optionsFor()` is public so AssertionVerifier can rebuild, unchanged, the
 * exact options a login ceremony started with, given the challenge
 * PasskeyChallengeStore returns on consume() — same reasoning as
 * RegistrationOptionsFactory: a second, independently written copy could
 * drift from what the browser was shown.
 */
final readonly class AssertionOptionsFactory
{
    private const int CHALLENGE_LENGTH_BYTES = 32;

    public function __construct(
        private PasskeyCeremony $ceremony,
        private PasskeyChallengeStore $challengeStore,
        private PasskeyRelyingParty $relyingParty,
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
