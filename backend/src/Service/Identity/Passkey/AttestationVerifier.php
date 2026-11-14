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
 * Verifies a registration attestation and stores the credential. The challenge is consumed and its owner checked
 * before the credential bytes are read, and the user handle comes from the consumed challenge, never re-minted.
 * Why: docs/security.md#passkey-ceremonies
 */
final readonly class AttestationVerifier
{
    public function __construct(
        private PasskeyChallengeStore $challengeStore,
        private PasskeyCeremony $ceremony,
        private RegistrationOptionsFactory $optionsFactory,
        private PasskeyOffer $offer,
        private EntityManagerInterface $entityManager,
        private UserPasskeyFactory $passkeyFactory,
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
        $passkey = $this->passkeyFactory->create($user, $credentialRecord, $attestation->label);

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
     * Builds the options outside checkAgainstLibrary()'s broad catch: a database failure in excludeListFor() is a
     * fault, not a credential to reject.
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
     * Every byte here is attacker-controlled and the library throws too many types to list, so the catch is broad.
     * That is safe only while nothing else runs inside it.
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
     * A unique-constraint hit means a replayed or forged registration (excludeListFor() already names every
     * credential to an honest authenticator): a 409, never a 500.
     */
    private function persist(User $user, UserPasskey $passkey): void
    {
        $this->entityManager->persist($passkey);

        // Before the flush: one flush stores both, so the offer is never stamped for a credential insert that failed.
        $this->offer->markAnswered($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new DuplicatePasskeyException();
        }
    }
}
