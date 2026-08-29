<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Service\Settings\PasskeyRelyingParty;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Random\RandomException;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Builds the options for a WebAuthn registration ("attestation") ceremony
 * (#624): resident-key discoverable credentials only — the login flow reads
 * one back with no username step — user verification required because the
 * passkey is this account's sole factor, and no attestation conveyance,
 * since this instance never inspects an attestation statement and asking for
 * one would only make the browser's enrolment prompt collect data nobody
 * reads.
 *
 * `PublicKeyCredentialRpEntity` is built with an empty name deliberately.
 * `web-auth/webauthn-lib` 5.3 deprecated passing a non-empty `$name` to
 * `PublicKeyCredentialEntity`'s constructor (removed in 6.0), and
 * `PublicKeyCredentialRpEntity` forwards its constructor argument to that
 * parent unshielded — unlike `PublicKeyCredentialUserEntity`, which always
 * passes `''` up and sets its own `$name` property directly afterwards. This
 * project's PHPUnit configuration turns that deprecation into a test failure
 * (`failOnDeprecation`), so `PasskeyRelyingParty::name()` is intentionally
 * not threaded through here; the normalizer already drops an empty `rp.name`
 * from the wire payload rather than emitting an empty string, so nothing is
 * lost on the wire either.
 */
final readonly class RegistrationOptionsFactory
{
    private const int CHALLENGE_LENGTH_BYTES = 32;

    public function __construct(
        private PasskeyCeremony $ceremony,
        private PasskeyChallengeStore $challengeStore,
        private PasskeyRelyingParty $relyingParty,
        private PasskeyCredentials $credentials,
    ) {
    }

    /**
     * @return array{options: array<string, mixed>, handle: string}
     *
     * @throws RandomException
     */
    public function create(User $user): array
    {
        $challenge = random_bytes(self::CHALLENGE_LENGTH_BYTES);

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('', $this->relyingParty->id()),
            user: $this->userEntityFor($user),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(ES256::ID),
                PublicKeyCredentialParameters::createPk(RS256::ID),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->credentials->excludeListFor($user),
        );

        return [
            'options' => $this->serialize($options),
            'handle' => $this->challengeStore->issue($challenge, $user->getId()),
        ];
    }

    private function userEntityFor(User $user): PublicKeyCredentialUserEntity
    {
        $handle = Base64UrlSafe::decodeNoPadding($this->credentials->userHandleFor($user));

        return PublicKeyCredentialUserEntity::create($user->getEmail(), $handle, $user->getEmail());
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PublicKeyCredentialCreationOptions $options): array
    {
        $json = $this->ceremony->serializer()->serialize($options, 'json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
