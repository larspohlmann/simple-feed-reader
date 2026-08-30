<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\Exception\AssertionRejectedException;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Verifies a WebAuthn assertion ("login") response and resolves it to the
 * stored UserPasskey it was signed by (#624) — the credential
 * PasskeyAuthenticator takes its user from. This class never mints a JWT and
 * never touches the security token storage: it answers exactly one question,
 * "did an enrolled authenticator sign this challenge?", and hands the caller
 * a persisted entity to build a Passport from.
 *
 * The steps run in the same deliberate order AttestationVerifier's do: the
 * challenge is consumed first, so a replayed or expired handle is rejected
 * before any attacker-controlled bytes are even parsed.
 *
 * $userHandle passed to AuthenticatorAssertionResponseValidator::check() is
 * always the STORED value read off the resolved UserPasskey, never one the
 * client supplied — spec §4.5. An assertion resolves the account from the
 * credential id alone; it must never trust a user handle the caller sent,
 * since discoverable login exists precisely because the server does not
 * know who is asking until the credential says so.
 *
 * Do NOT re-implement the signature-counter comparison here.
 * PasskeyCeremony::request() already wires CheckCounter to the library's
 * ThrowExceptionIfInvalid, which is what actually rejects a counter that
 * failed to advance — the standard defence against a cloned authenticator.
 * This class's only job around that check is to log the rejection with the
 * identifiers an incident response would need; see logRejectedCounter().
 *
 * The options AuthenticatorAssertionResponseValidator checks against come
 * from AssertionOptionsFactory::optionsFor() — the SAME method the options
 * endpoint uses to build what the browser was shown — rather than a second,
 * private copy here. AssertionOptionsFactory's own docblock explains why:
 * two independently written copies of the user-verification requirement
 * could silently drift apart (#624 Task 10, fix round 1; before this fix
 * they were byte-identical, an accident waiting to happen).
 *
 * verify() FLUSHES explicitly (#624 Task 10, fix round 1). Before this fix
 * the new counter and lastUsedAt were only ever written because
 * StampLastLoginOnTokenIssue happens to flush the whole UnitOfWork on
 * JWTCreatedEvent — an accidental dependency: if that listener ever stopped
 * flushing, a cloned authenticator's advancing counter would silently stop
 * being recorded, and CheckCounter's replay defence would quietly go blind.
 * This class owns the entity it mutates, so it owns persisting the mutation.
 */
final readonly class AssertionVerifier
{
    public function __construct(
        private PasskeyChallengeStore $challengeStore,
        private PasskeyCeremony $ceremony,
        private AssertionOptionsFactory $optionsFactory,
        private UserPasskeyRepository $passkeys,
        private EntityManagerInterface $em,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $credential
     *
     * @throws InvalidArgumentException
     */
    public function verify(string $handle, array $credential): UserPasskey
    {
        $challenge = $this->challengeStore->consume($handle);

        [$rawCredentialId, $response] = $this->parse($credential);
        $storedPasskey = $this->resolveCredential($rawCredentialId);

        $newCounter = $this->checkAssertion($storedPasskey, $response, $challenge->challenge);
        $storedPasskey->recordUse($this->clock->now(), $newCounter);
        $this->em->flush();

        return $storedPasskey;
    }

    /**
     * Everything in THIS method runs on bytes an attacker fully controls —
     * the WebAuthn deserializer and the CBOR decoder underneath it — so the
     * catch is deliberately broad, the same reasoning
     * AttestationVerifier::checkAgainstLibrary() gives for its own. Returns
     * the raw credential id alongside the narrowed response, rather than the
     * whole PublicKeyCredential, so the instanceof guard below is the only
     * place that needs to know its $response property is not yet narrowed.
     *
     * @param array<string, mixed> $credential
     *
     * @return array{0: string, 1: AuthenticatorAssertionResponse}
     */
    private function parse(array $credential): array
    {
        try {
            $json = json_encode($credential, \JSON_THROW_ON_ERROR);

            /** @var PublicKeyCredential $publicKeyCredential */
            $publicKeyCredential = $this->ceremony->serializer()->deserialize(
                $json,
                PublicKeyCredential::class,
                'json',
            );
            $response = $publicKeyCredential->response;

            $response instanceof AuthenticatorAssertionResponse
                || throw new \UnexpectedValueException('Expected an assertion response, got an attestation response.');

            return [$publicKeyCredential->rawId, $response];
        } catch (\Throwable $exception) {
            throw new AssertionRejectedException($exception);
        }
    }

    /**
     * `credential_id` is unique across every account (UserPasskey's own
     * unique constraint), so this lookup carries no user — the whole point
     * of a discoverable-credential login is that the server does not know
     * who is asking until this resolves it.
     */
    private function resolveCredential(string $rawCredentialId): UserPasskey
    {
        $credentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);

        return $this->passkeys->findOneByCredentialId($credentialId) ?? throw new AssertionRejectedException();
    }

    /**
     * Builds a CredentialRecord from the STORED row, checks the assertion
     * against it, and returns the new signature counter for the caller to
     * persist. See the class docblock for why $userHandle is read off
     * $storedPasskey rather than trusted from the client.
     */
    private function checkAssertion(
        UserPasskey $storedPasskey,
        AuthenticatorAssertionResponse $response,
        string $challenge,
    ): int {
        $record = $this->credentialRecordFor($storedPasskey);
        $userHandle = Base64UrlSafe::decodeNoPadding($storedPasskey->getUserHandle());

        try {
            $verified = AuthenticatorAssertionResponseValidator::create($this->ceremony->request())->check(
                $record,
                $response,
                $this->optionsFactory->optionsFor($challenge),
                $this->ceremony->host(),
                $userHandle,
            );
        } catch (CounterException $exception) {
            $this->logRejectedCounter($storedPasskey);

            throw new AssertionRejectedException($exception);
        } catch (\Throwable $exception) {
            throw new AssertionRejectedException($exception);
        }

        return $verified->counter;
    }

    /**
     * The one piece of behaviour this class adds around CheckCounter: a
     * counter that failed to advance means either a cloned authenticator or
     * a replayed assertion, and that is worth an operator's attention even
     * though the caller only ever sees a generic login failure.
     */
    private function logRejectedCounter(UserPasskey $storedPasskey): void
    {
        $this->logger->warning('Passkey login rejected: the signature counter did not advance.', [
            'credentialId' => $storedPasskey->getCredentialId(),
            'userId' => $storedPasskey->getUser()->getId(),
        ]);
    }

    private function credentialRecordFor(UserPasskey $storedPasskey): CredentialRecord
    {
        return CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($storedPasskey->getCredentialId()),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $storedPasskey->getTransports(),
            'none',
            EmptyTrustPath::create(),
            self::aaguidOrNil($storedPasskey->getAaguid()),
            Base64UrlSafe::decodeNoPadding($storedPasskey->getPublicKey()),
            Base64UrlSafe::decodeNoPadding($storedPasskey->getUserHandle()),
            $storedPasskey->getSignatureCounter(),
        );
    }

    /**
     * Mirrors AttestationVerifier::aaguidOrNull() in reverse: this column is
     * nullable in storage for the same "no AAGUID assigned" reason, so a
     * null stored value is rehydrated back to the spec's all-zero sentinel
     * the library's own Uuid type expects.
     */
    private static function aaguidOrNil(?string $aaguid): Uuid
    {
        return null === $aaguid ? new NilUuid() : Uuid::fromString($aaguid);
    }
}
