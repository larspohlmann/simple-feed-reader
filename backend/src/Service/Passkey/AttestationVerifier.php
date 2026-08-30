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
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;

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
    /**
     * UserPasskey::$credentialId is VARCHAR(255): see
     * guardCredentialIdFitsColumn() for why that needs enforcing here rather
     * than left to the database (#624, fix round 2).
     */
    private const int CREDENTIAL_ID_COLUMN_MAX_LENGTH = 255;

    public function __construct(
        private PasskeyChallengeStore $challengeStore,
        private PasskeyCeremony $ceremony,
        private RegistrationOptionsFactory $optionsFactory,
        private PasskeyOffer $offer,
        private EntityManagerInterface $em,
        private NaiveUtcClock $clock,
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
     * Resolves the user handle and rebuilds the creation options BEFORE the
     * broad catch below, deliberately: optionsFor() reaches the database
     * through PasskeyCredentials::excludeListFor(), and a failure there is a
     * real fault (a database outage, say), not a credential to reject. Only
     * the actual parsing of attacker-controlled bytes belongs inside that
     * catch — see checkAgainstLibrary() (#624, fix round 2).
     *
     * @param array<string, mixed> $credential
     */
    private function check(User $user, PasskeyChallenge $challenge, array $credential): CredentialRecord
    {
        $userHandle = $challenge->userHandle ?? throw new \UnexpectedValueException(
            'A registration challenge must always carry a user handle.',
        );
        $options = $this->optionsFactory->optionsFor($user, $challenge->challenge, $userHandle);

        return $this->checkAgainstLibrary($credential, $options);
    }

    /**
     * Everything in THIS method runs on bytes an attacker fully controls —
     * the WebAuthn deserializer, the CBOR decoder underneath it, and the
     * ceremony's own checks. Between them they throw a scatter of types that
     * is impractical to enumerate exhaustively (the library's own
     * WebauthnException hierarchy, Symfony's serializer exceptions, and
     * plain SPL RuntimeException/InvalidArgumentException/TypeError from the
     * CBOR decoder on malformed input), so the catch is deliberately broad.
     * That is safe specifically because nothing else runs in this scope:
     * $options is built by the caller, outside the catch, for exactly that
     * reason.
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
        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);
        self::guardCredentialIdFitsColumn($credentialId);

        return new UserPasskey(
            $user,
            $credentialId,
            Base64UrlSafe::encodeUnpadded($record->userHandle),
            Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
            $record->counter,
            self::aaguidOrNull($record->aaguid),
            self::knownTransports($record->transports),
            $label,
            $this->clock->now(),
        );
    }

    /**
     * The library's own CheckCredentialId step only rejects a credential id
     * over 1023 RAW bytes — the spec's own ceiling, and far more than
     * UserPasskey::$credentialId's VARCHAR(255) column holds once
     * base64url-encoded (~191 raw bytes). MySQL enforces that column width
     * and would otherwise surface a data-too-long DBAL exception at flush
     * time — a DIFFERENT exception than the UniqueConstraintViolationException
     * persist() already catches, so it would reach the kernel as an
     * unhandled 500. SQLite does not enforce VARCHAR width at all, which is
     * why this can only be caught here, before the write is even attempted,
     * never at the database (#624, fix round 2).
     */
    private static function guardCredentialIdFitsColumn(string $credentialId): void
    {
        \strlen($credentialId) <= self::CREDENTIAL_ID_COLUMN_MAX_LENGTH || throw new AttestationRejectedException(
            new \LengthException('Credential id is too long to store.'),
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

    /**
     * `response.transports` is client-supplied wire data the WebAuthn
     * library never validates at all — AuthenticatorAttestationResponseDenormalizer
     * assigns it verbatim — and PasskeyCredentials::excludeListFor() later
     * echoes whatever is stored here straight back to a browser on every
     * future registration attempt. Filtering to the spec's own enum before
     * persisting is what keeps that round trip from carrying arbitrary
     * client-supplied strings (#624, fix round 2).
     *
     * @param array<string> $transports
     *
     * @return list<string>
     */
    private static function knownTransports(array $transports): array
    {
        return array_values(array_intersect($transports, PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORTS));
    }
}
