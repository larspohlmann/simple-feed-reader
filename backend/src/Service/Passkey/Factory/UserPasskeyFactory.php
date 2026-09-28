<?php

declare(strict_types=1);

namespace App\Service\Passkey\Factory;

use App\Entity\PasskeyRegistration;
use App\Entity\User;
use App\Entity\UserPasskey;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Passkey\Exception\AttestationRejectedException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;

final readonly class UserPasskeyFactory
{
    /**
     * UserPasskey::$credentialId is VARCHAR(255): see
     * guardCredentialIdFitsColumn() for why that needs enforcing here rather
     * than left to the database.
     */
    private const int CREDENTIAL_ID_COLUMN_MAX_LENGTH = 255;

    public function __construct(private NaiveUtcClock $clock)
    {
    }

    public function create(User $user, CredentialRecord $record, string $label): UserPasskey
    {
        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);
        self::guardCredentialIdFitsColumn($credentialId);

        return new UserPasskey($user, new PasskeyRegistration(
            credentialId: $credentialId,
            userHandle: Base64UrlSafe::encodeUnpadded($record->userHandle),
            publicKey: Base64UrlSafe::encodeUnpadded($record->credentialPublicKey),
            signatureCounter: $record->counter,
            aaguid: self::aaguidOrNull($record->aaguid),
            transports: self::knownTransports($record->transports),
            label: $label,
            registeredAt: $this->clock->now(),
        ));
    }

    /**
     * The library's CheckCredentialId step only rejects an id over 1023 RAW
     * bytes — the spec's ceiling, far more than
     * UserPasskey::$credentialId's VARCHAR(255) holds once base64url-encoded
     * (~191 raw bytes). MySQL would surface a data-too-long DBAL exception
     * at flush — DIFFERENT from the UniqueConstraintViolationException
     * AttestationVerifier::persist() catches, so it would reach the kernel as
     * an unhandled 500. SQLite doesn't enforce VARCHAR width at all, so this
     * must be caught here, before the write, never at the database.
     */
    private static function guardCredentialIdFitsColumn(string $credentialId): void
    {
        if (\strlen($credentialId) > self::CREDENTIAL_ID_COLUMN_MAX_LENGTH) {
            throw new AttestationRejectedException(
                new \LengthException('Credential id is too long to store.'),
            );
        }
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
     * library never validates — AuthenticatorAttestationResponseDenormalizer
     * assigns it verbatim — and PasskeyCredentials::excludeListFor() later
     * echoes whatever is stored here back to the browser on every future
     * registration. Filtering to the spec's enum before persisting keeps
     * that round trip from carrying arbitrary client strings.
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
