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
 * Builds options for a WebAuthn registration ("attestation") ceremony
 * (#624): resident-key discoverable credentials only, user verification
 * required since the passkey is this account's sole factor, and no
 * attestation conveyance since nothing here inspects an attestation
 * statement.
 *
 * `rp.name` is set on the serialised array after building
 * `PublicKeyCredentialRpEntity` with an empty name, rather than through its
 * constructor: `rp.name` is a required WebAuthn IDL member, but
 * `web-auth/webauthn-lib` 5.3 deprecated passing it there directly.
 *
 * `create()` mints the user handle once, via `PasskeyCredentials::
 * userHandleFor()`, and threads it through both the options and
 * `PasskeyChallengeStore::issue()`. `optionsFor()` takes the handle as a
 * parameter rather than deriving it, so `AttestationVerifier` rebuilds the
 * same options from the value stored on the consumed challenge instead of
 * minting a new one — `userHandleFor()` mints fresh random values while an
 * account has no credential yet.
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
        $userHandle = $this->credentials->userHandleFor($user);
        $options = $this->optionsFor($user, $challenge, $userHandle);

        return [
            'options' => $this->serializeWithRelyingPartyName($options),
            'handle' => $this->challengeStore->issue($challenge, $user->getId(), $userHandle),
        ];
    }

    /**
     * Rebuilds the exact options a creation ceremony started with, given the
     * same user, challenge and user handle PasskeyChallengeStore returns on
     * consume(). Pulled out of create() so AttestationVerifier can share it:
     * the resident-key and user-verification requirements below are
     * security-relevant, and a second, independently written copy could
     * silently drift from what the browser was actually shown — for instance
     * stop enforcing user verification — without either call site's tests
     * noticing.
     */
    public function optionsFor(User $user, string $challenge, string $userHandle): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('', $this->relyingParty->id()),
            user: self::userEntityFor($user, $userHandle),
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

    private static function userEntityFor(User $user, string $userHandle): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $user->getEmail(),
            Base64UrlSafe::decodeNoPadding($userHandle),
            $user->getEmail(),
        );
    }

    /**
     * The class docblock explains why `rp.name` is stitched in here rather
     * than passed to `PublicKeyCredentialRpEntity`.
     *
     * @return array<string, mixed>
     */
    private function serializeWithRelyingPartyName(PublicKeyCredentialCreationOptions $options): array
    {
        $decoded = $this->ceremony->encode($options);

        /** @var array<string, mixed> $relyingParty */
        $relyingParty = \is_array($decoded['rp'] ?? null) ? $decoded['rp'] : [];
        $relyingParty['name'] = $this->relyingParty->name();
        $decoded['rp'] = $relyingParty;

        return $decoded;
    }
}
