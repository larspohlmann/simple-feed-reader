<?php

declare(strict_types=1);

namespace App\Service\Passkey\Factory;

use App\Entity\User;
use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Passkey\PasskeyCredentials;
use App\Service\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;
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
 * Registration options: resident keys, user verification required, no attestation. create() mints the user handle
 * once and stores it with the challenge; AttestationVerifier rebuilds the options through optionsFor() from that
 * stored handle, since userHandleFor() mints anew while an account has no credential.
 */
final readonly class RegistrationOptionsFactory
{
    private const int CHALLENGE_LENGTH_BYTES = 32;

    public function __construct(
        private PasskeyCeremony $ceremony,
        private PasskeyChallengeStore $challengeStore,
        private PasskeyRelyingPartyInterface $relyingParty,
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
     * The exact options a ceremony started with, shared with AttestationVerifier: a second copy of these
     * security-relevant requirements could drift from what the browser was shown.
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
     * `rp.name` is required, but webauthn-lib 5.3 deprecated passing it to PublicKeyCredentialRpEntity, so it is set
     * on the serialised array.
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
