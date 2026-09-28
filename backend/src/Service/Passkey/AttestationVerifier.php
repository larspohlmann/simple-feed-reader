<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Exception\DuplicatePasskeyException;
use App\Service\Passkey\Exception\PasskeyChallengeOwnershipException;
use App\Service\Passkey\Factory\RegistrationOptionsFactory;
use App\Service\Passkey\Factory\UserPasskeyFactory;
use App\Service\Passkey\Model\PasskeyAttestationModel;
use App\Service\Passkey\Model\PasskeyChallengeModel;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Verifies a WebAuthn attestation ("registration") response and turns it
 * into a stored UserPasskey (#624) — the highest-risk step in enrolment,
 * since everything downstream (login, the credential list, revocation)
 * trusts that a `user_passkey` row really was produced by a ceremony an
 * authenticator completed.
 *
 * The steps below are deliberately ordered: the challenge is consumed and
 * its ownership checked BEFORE the credential bytes are looked at, so a
 * caller who doesn't own the challenge never learns whether their forged
 * credential would otherwise have parsed.
 *
 * This class never calls PasskeyCredentials::userHandleFor() — it reads the
 * user handle straight off the consumed PasskeyChallengeModel. See that class's
 * docblock for why re-minting one here would be a real bug: userHandleFor()
 * returns a fresh random value per call for an account's first credential,
 * and options/verification are two separate HTTP requests.
 */
final readonly class AttestationVerifier
{
    public function __construct(
        private PasskeyChallengeStore $challengeStore,
        private PasskeyCeremony $ceremony,
        private RegistrationOptionsFactory $optionsFactory,
        private PasskeyOffer $offer,
        private EntityManagerInterface $em,
        private UserPasskeyFactory $passkeys,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function verifyAndStore(User $user, PasskeyAttestationModel $attestation): UserPasskey
    {
        $challenge = $this->challengeStore->consume($attestation->handle);
        $this->guardOwnership($user, $challenge);

        $credentialRecord = $this->check($user, $challenge, $attestation->credential);
        $passkey = $this->passkeys->create($user, $credentialRecord, $attestation->label);

        $this->persist($user, $passkey);

        return $passkey;
    }

    private function guardOwnership(User $user, PasskeyChallengeModel $challenge): void
    {
        if ($challenge->userId !== $user->getId()) {
            throw new PasskeyChallengeOwnershipException();
        }
    }

    /**
     * Resolves the user handle and rebuilds the creation options BEFORE the
     * broad catch below, deliberately: optionsFor() reaches the database
     * through PasskeyCredentials::excludeListFor(), and a failure there is a
     * real fault (a database outage), not a credential to reject. Only
     * parsing of attacker-controlled bytes belongs inside that catch — see
     * checkAgainstLibrary().
     *
     * @param array<string, mixed> $credential
     */
    private function check(User $user, PasskeyChallengeModel $challenge, array $credential): CredentialRecord
    {
        $userHandle = $challenge->userHandle ?? throw new \UnexpectedValueException(
            'A registration challenge must always carry a user handle.',
        );
        $options = $this->optionsFactory->optionsFor($user, $challenge->challenge, $userHandle);

        return $this->checkAgainstLibrary($credential, $options);
    }

    /**
     * Everything here runs on bytes an attacker fully controls — the
     * WebAuthn deserializer, the CBOR decoder, and the ceremony's own
     * checks — which between them throw too wide a scatter of types to
     * enumerate (the library's WebauthnException hierarchy, Symfony's
     * serializer exceptions, plain SPL exceptions from malformed CBOR), so
     * the catch is deliberately broad. That's safe only because nothing
     * else runs in this scope: $options is built by the caller, outside the
     * catch.
     *
     * @param array<string, mixed> $credential
     */
    private function checkAgainstLibrary(
        array $credential,
        PublicKeyCredentialCreationOptions $options,
    ): CredentialRecord {
        try {
            $response = $this->deserialize($credential);

            return AuthenticatorAttestationResponseValidator::create($this->ceremony->creation())
                ->check($response, $options, $this->ceremony->host());
        } catch (\Throwable $exception) {
            throw new AttestationRejectedException($exception);
        }
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function deserialize(array $credential): AuthenticatorAttestationResponse
    {
        $json = json_encode($credential, \JSON_THROW_ON_ERROR);

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $this->ceremony->serializer()->deserialize($json, PublicKeyCredential::class, 'json');

        $publicKeyCredential->response instanceof AuthenticatorAttestationResponse
            || throw new \UnexpectedValueException('Expected an attestation response, got an assertion response.');

        return $publicKeyCredential->response;
    }

    /**
     * The credential id is unique across every account, and
     * PasskeyCredentials::excludeListFor() already tells an honest
     * authenticator about every credential this account holds — so hitting
     * the database's own constraint here means a replayed or forged
     * registration, not a bug, and must not reach the client as a 500.
     */
    private function persist(User $user, UserPasskey $passkey): void
    {
        $this->em->persist($passkey);

        // Marked before the flush, not after: markAnswered() only mutates the
        // already-managed Preferences entity, so one flush covers both. Two
        // flushes would risk stamping the offer on a request whose credential
        // insert then failed.
        $this->offer->markAnswered($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new DuplicatePasskeyException();
        }
    }
}
