<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Settings\PasskeyRelyingParty;
use Random\RandomException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Builds the options for a WebAuthn login ("assertion") ceremony (#624):
 * discoverable-credential only, so `allowCredentials` is always empty and
 * `create()` takes no e-mail or user id to look one up with — the whole
 * point of this flow is that the server does not know who is asking until
 * the authenticator answers.
 *
 * `userVerification` is REQUIRED, not preferred: the passkey is this
 * account's sole authentication factor, so an authenticator that could skip
 * verification would be skipping the only check standing between "the
 * device is unlocked" and "this account is logged in".
 *
 * No enumeration: `create()` takes no parameter that could vary with
 * whether an account exists, so the response shape and cost are identical
 * for every caller regardless of what they are probing for. The stored
 * challenge carries a null user id and null user handle for the same
 * reason — see PasskeyChallenge's docblock.
 *
 * Unlike RegistrationOptionsFactory, this class does not touch
 * PasskeyCeremony::request() — that `CeremonyStepManager` verifies an
 * assertion response, which is AssertionVerifier's job, not this one's. It
 * also does not need the `rp.name`-after-serialisation stitch its sibling
 * does: `PublicKeyCredentialRequestOptions::$rpId` is a plain, un-deprecated
 * constructor property, not a value the library's own constructor refuses
 * to accept.
 *
 * `optionsFor()` is public and reused, unchanged, by AssertionVerifier to
 * rebuild the exact same options a login ceremony was started with, given
 * the challenge PasskeyChallengeStore handed back on consume() — the same
 * reasoning RegistrationOptionsFactory's own optionsFor() gives for
 * AttestationVerifier's sake: a second, independently written copy of the
 * resident-key/user-verification requirements could silently drift from
 * what the browser was shown, without either call site's tests noticing.
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
