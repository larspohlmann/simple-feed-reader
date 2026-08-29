<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Entity\UserPasskey;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Exception\DuplicatePasskeyException;
use App\Service\Passkey\Exception\PasskeyChallengeOwnershipException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;

/**
 * Verifies a WebAuthn attestation ("registration") response and turns it
 * into a stored UserPasskey (#624) — the highest-risk step in the enrolment
 * flow, since everything downstream (login, the credential list, revocation)
 * trusts that a row in `user_passkey` really was produced by a ceremony an
 * authenticator completed.
 *
 * The steps below are deliberately in this order: the challenge is consumed
 * and its ownership checked BEFORE the credential bytes are even looked at,
 * so a caller who does not own the challenge never learns whether their
 * forged credential would otherwise have parsed.
 *
 * This class never calls PasskeyCredentials::userHandleFor() — it reads the
 * user handle straight off the consumed PasskeyChallenge instead. See that
 * class's docblock (#624, fix round 1) for why re-minting one here would be
 * a real bug: userHandleFor() returns a fresh random value on every call for
 * an account's first credential, and options and verification are two
 * separate HTTP requests.
 */
final readonly class AttestationVerifier
{
    public function __construct(
        private PasskeyChallengeStore $challengeStore,
        private PasskeyCeremony $ceremony,
        private RegistrationOptionsFactory $optionsFactory,
        private PasskeyOffer $offer,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function verifyAndStore(User $user, RegisterPasskeyRequest $request): UserPasskey
    {
        $challenge = $this->challengeStore->consume($request->handle);
        $this->guardOwnership($user, $challenge);

        $credentialRecord = $this->check($user, $challenge, $request->credential);
        $passkey = $this->passkeyFrom($user, $credentialRecord, $request->label);

        $this->persist($user, $passkey);

        return $passkey;
    }

    private function guardOwnership(User $user, PasskeyChallenge $challenge): void
    {
        if ($challenge->userId !== $user->getId()) {
            throw new PasskeyChallengeOwnershipException();
        }
    }

    /**
     * Everything in this method runs on bytes an attacker fully controls —
     * the WebAuthn deserializer, the CBOR decoder underneath it, and the
     * ceremony's own checks. Between them they throw a scatter of types that
     * is impractical to enumerate exhaustively (the library's own
     * WebauthnException hierarchy, Symfony's serializer exceptions, and
     * plain SPL RuntimeException/InvalidArgumentException/TypeError from the
     * CBOR decoder on malformed input), so the catch is deliberately broad.
     * That is safe specifically because nothing in this method's scope is
     * OUR code: whatever is thrown here is, by construction, a rejection of
     * the credential, never a bug this listener should surface as a 500.
     *
     * @param array<string, mixed> $credential
     */
    private function check(User $user, PasskeyChallenge $challenge, array $credential): CredentialRecord
    {
        try {
            $response = $this->deserialize($credential);
            $userHandle = $challenge->userHandle ?? throw new \UnexpectedValueException(
                'A registration challenge must always carry a user handle.',
            );
            $options = $this->optionsFactory->optionsFor($user, $challenge->challenge, $userHandle);

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
     * authenticator about every credential this account holds — so reaching
     * the database's own constraint here means a replayed or forged
     * registration, not a bug, and it must not reach the client as a 500.
     */
    private function persist(User $user, UserPasskey $passkey): void
    {
        $this->em->persist($passkey);

        // Marked before the flush below, not after: markAnswered() only
        // mutates the already-managed Preferences entity, so one flush
        // covers both this and the new UserPasskey row. Two flushes would
        // risk leaving the offer stamped on a request whose credential
        // insert then failed.
        $this->offer->markAnswered($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new DuplicatePasskeyException();
        }
    }

    private function passkeyFrom(User $user, CredentialRecord $record, string $label): UserPasskey
    {
        return new UserPasskey(
            $user,
            Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            Base64UrlSafe::encodeUnpadded($record->userHandle),
            Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
            $record->counter,
            self::aaguidOrNull($record->aaguid),
            array_values($record->transports),
            $label,
            $this->nowAsNaiveUtc(),
        );
    }

    /**
     * The spec's "no AAGUID assigned" value is all zero bits; storing that
     * literally would suggest a real, meaningful identifier where there is
     * none, so it is normalised to null the same way an absent value would
     * be.
     */
    private static function aaguidOrNull(Uuid $aaguid): ?string
    {
        return (new NilUuid())->equals($aaguid) ? null : $aaguid->toRfc4122();
    }

    /** Doctrine persists naive wall-clock values, so a non-UTC clock must be normalised first. */
    private function nowAsNaiveUtc(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
