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
 * `PublicKeyCredentialRpEntity` is built with an empty name, and `rp.name` is
 * then set on the serialised array afterwards, from `PasskeyRelyingParty::
 * name()` — NOT by threading the configured name through the entity's own
 * constructor. Do not "clean this up" back to an empty name: `rp.name` is
 * still a REQUIRED member of the WebAuthn IDL, and an empty one degrades the
 * browser's and the password manager's enrolment prompt to showing the bare
 * domain instead of the admin-configured name (#624, Task 3). The two-step
 * shape exists only because `web-auth/webauthn-lib` 5.3 deprecated passing a
 * non-empty `$name` to `PublicKeyCredentialEntity`'s constructor (removed in
 * 6.0) — `PublicKeyCredentialRpEntity` forwards its constructor argument to
 * that parent unshielded, unlike `PublicKeyCredentialUserEntity`, which
 * always passes `''` up and sets its own `$name` property directly
 * afterwards — and this project's PHPUnit configuration turns that
 * deprecation into a test failure (`failOnDeprecation`). The library
 * deprecated its own carrier for this field; the wire contract we hand the
 * browser is ours to fill in regardless.
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
        $options = $this->optionsFor($user, $challenge);

        return [
            'options' => $this->serializeWithRelyingPartyName($options),
            'handle' => $this->challengeStore->issue($challenge, $user->getId()),
        ];
    }

    /**
     * Rebuilds the exact same options a creation ceremony was started with,
     * given the same user and the challenge PasskeyChallengeStore handed
     * back on consume(). Pulled out of create() so AttestationVerifier can
     * share it: the resident-key and user-verification requirements below
     * are security-relevant, and a second, independently written copy of
     * this construction could silently drift from the one the browser was
     * actually shown — for instance stop enforcing user verification —
     * without either call site's own tests noticing.
     */
    public function optionsFor(User $user, string $challenge): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
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
    }

    private function userEntityFor(User $user): PublicKeyCredentialUserEntity
    {
        $handle = Base64UrlSafe::decodeNoPadding($this->credentials->userHandleFor($user));

        return PublicKeyCredentialUserEntity::create($user->getEmail(), $handle, $user->getEmail());
    }

    /**
     * The class docblock explains why `rp.name` is stitched in here rather
     * than passed to `PublicKeyCredentialRpEntity`.
     *
     * @return array<string, mixed>
     */
    private function serializeWithRelyingPartyName(PublicKeyCredentialCreationOptions $options): array
    {
        $decoded = $this->serialize($options);

        /** @var array<string, mixed> $relyingParty */
        $relyingParty = \is_array($decoded['rp'] ?? null) ? $decoded['rp'] : [];
        $relyingParty['name'] = $this->relyingParty->name();
        $decoded['rp'] = $relyingParty;

        return $decoded;
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
