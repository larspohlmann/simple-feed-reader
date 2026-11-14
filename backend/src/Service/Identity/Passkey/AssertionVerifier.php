<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use App\Service\Passkey\Factory\AssertionOptionsFactory;
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
 * Verifies a login assertion against the stored UserPasskey it names. The challenge is consumed before any client
 * bytes are parsed, and the user handle checked is the stored one, never the client's. The counter check is the
 * library's (PasskeyCeremony::request()). Why each step: docs/security.md#passkey-ceremonies
 */
final readonly class AssertionVerifier
{
    public function __construct(
        private PasskeyChallengeStore $challengeStore,
        private PasskeyCeremony $ceremony,
        private AssertionOptionsFactory $optionsFactory,
        private UserPasskeyRepository $passkeys,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
        private PasskeySignInAvailability $availability,
    ) {
    }

    /**
     * The availability guard runs before the challenge is consumed: the login path has no controller action to
     * gate, so this is the one place that can refuse a disabled instance's login.
     *
     * @param array<string, mixed> $credential
     *
     * @throws InvalidArgumentException
     */
    public function verify(string $handle, array $credential): UserPasskey
    {
        $this->availability->guard();

        $challenge = $this->challengeStore->consume($handle);

        [$rawCredentialId, $response] = $this->parse($credential);
        $storedPasskey = $this->resolveCredential($rawCredentialId);

        $newCounter = $this->checkAssertion($storedPasskey, $response, $challenge->challenge);
        $storedPasskey->recordUse($this->clock->now(), $newCounter);
        $this->entityManager->flush();

        return $storedPasskey;
    }

    /**
     * Every byte here is attacker-controlled (the WebAuthn deserializer and the CBOR decoder under it), so the catch
     * is broad.
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
     * `credential_id` is unique across every account, so the lookup carries no user. A miss keeps its own type (see
     * UnknownPasskeyCredentialException).
     */
    private function resolveCredential(string $rawCredentialId): UserPasskey
    {
        $credentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);

        return $this->passkeys->findOneByCredentialId($credentialId)
            ?? throw new UnknownPasskeyCredentialException();
    }

    /** Checks the assertion against a CredentialRecord built from the stored row and returns the new counter. */
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
     * A counter that failed to advance means a cloned authenticator or a replayed assertion: an operator should see
     * it, though the client only gets a generic login failure.
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

    /** The inverse of UserPasskeyFactory::aaguidOrNull(): a stored null becomes the spec's all-zero AAGUID. */
    private static function aaguidOrNil(?string $aaguid): Uuid
    {
        return null === $aaguid ? new NilUuid() : Uuid::fromString($aaguid);
    }
}
