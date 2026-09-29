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
     * The library allows 1023 raw bytes; the column holds 191 once base64url-encoded. MySQL would fail the flush with
     * a data-too-long error (a 500, not the caught duplicate) and SQLite would not check, so the check is here.
     */
    private static function guardCredentialIdFitsColumn(string $credentialId): void
    {
        if (\strlen($credentialId) > self::CREDENTIAL_ID_COLUMN_MAX_LENGTH) {
            throw new AttestationRejectedException(
                new \LengthException('Credential id is too long to store.'),
            );
        }
    }

    /** The spec's all-zero "no AAGUID assigned" is stored as null, not as an identifier that looks real. */
    private static function aaguidOrNull(Uuid $aaguid): ?string
    {
        return (new NilUuid())->equals($aaguid) ? null : $aaguid->toRfc4122();
    }

    /**
     * `response.transports` is unvalidated client data that excludeListFor() echoes to every later registration, so
     * only the spec's values are stored.
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
