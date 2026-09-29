<?php

declare(strict_types=1);

namespace App\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Builds WebAuthn ceremonies in PHP: `attestation: none` signs nothing, so a registration is bytes in the spec's
 * layout, and assertion() signs `authData ‖ sha256(clientDataJSON)` with the enrolled key.
 *
 * @phpstan-import-type PasskeyCredentialPayload from PasskeyAttestationFixture
 * @phpstan-type PasskeyAssertionCredentialPayload array{
 *     id: string,
 *     rawId: string,
 *     type: string,
 *     response: array{
 *         clientDataJSON: string,
 *         authenticatorData: string,
 *         signature: string,
 *         userHandle: string,
 *     },
 * }
 */
final readonly class PasskeyFixtures
{
    private const string OPENSSL_EC_CURVE = 'prime256v1';
    private const int EC_COORDINATE_LENGTH_BYTES = 32;

    /**
     * All zero, the spec's "no AAGUID assigned"; attestation()'s default. AttestationVerifierTest passes a real one.
     */
    private const string AAGUID = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    /** Public so a test can build a fixture with user verification cleared: $flags defaults to requiring it. */
    final public const int FLAG_USER_PRESENT = 0x01;
    final public const int FLAG_USER_VERIFIED = 0x04;
    final public const int FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED = 0x40;

    private const int DEFAULT_FLAGS = self::FLAG_USER_PRESENT
        | self::FLAG_USER_VERIFIED
        | self::FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED;

    /**
     * An assertion's authenticatorData carries no attested credential data —
     * spec §6.1, only a registration ceremony's does — so this omits
     * FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED from DEFAULT_FLAGS above.
     */
    private const int DEFAULT_ASSERTION_FLAGS = self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED;

    private const int COSE_KEY_TYPE_EC2 = 2;
    private const int COSE_ALGORITHM_ES256 = -7;
    private const int COSE_CURVE_P256 = 1;

    /**
     * $flags defaults to UP|UV|AT, a valid attestation; clearing FLAG_USER_VERIFIED gives the one that must not
     * verify, as user verification is required.
     */
    public static function attestation(
        string $relyingPartyId,
        string $origin,
        string $challenge,
        string $credentialId,
        string $userHandle,
        int $signCount = 0,
        int $flags = self::DEFAULT_FLAGS,
        string $aaguid = self::AAGUID,
    ): PasskeyAttestationFixture {
        $privateKey = self::generatePrivateKey();
        [$x, $y] = self::publicKeyCoordinates($privateKey);
        $publicKeyCose = self::coseKeyBytes($x, $y);

        $authenticatorData = self::authenticatorData(
            $relyingPartyId,
            $credentialId,
            $publicKeyCose,
            $signCount,
            $flags,
            $aaguid,
        );
        $clientDataJson = self::clientDataJson('webauthn.create', $challenge, $origin);
        $attestationObject = self::attestationObject($authenticatorData);

        return new PasskeyAttestationFixture(
            self::credentialPayload($credentialId, $clientDataJson, $attestationObject),
            $challenge,
            $credentialId,
            $userHandle,
            $relyingPartyId,
            $origin,
            $privateKey,
        );
    }

    /**
     * A login assertion over the enrolled credential, signed with the key attestation() kept. The relying party,
     * origin and challenge are parameters, so a test can sign one identity and have the server check another.
     *
     * @return PasskeyAssertionCredentialPayload
     */
    public static function assertion(
        string $relyingPartyId,
        string $origin,
        string $challenge,
        PasskeyAttestationFixture $enrolledCredential,
        int $signCount = 1,
        int $flags = self::DEFAULT_ASSERTION_FLAGS,
    ): array {
        $authenticatorData = self::authenticatorDataForAssertion($relyingPartyId, $signCount, $flags);
        $clientDataJson = self::clientDataJson('webauthn.get', $challenge, $origin);
        $signature = self::sign($authenticatorData, $clientDataJson, $enrolledCredential->privateKey);

        return self::assertionCredentialPayload($enrolledCredential, [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
            'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
            'signature' => Base64UrlSafe::encodeUnpadded($signature),
            'userHandle' => Base64UrlSafe::encodeUnpadded($enrolledCredential->userHandle),
        ]);
    }

    private static function generatePrivateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'curve_name' => self::OPENSSL_EC_CURVE,
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
        ]);

        $key instanceof \OpenSSLAsymmetricKey || throw new \RuntimeException(
            'Unable to generate an EC P-256 key pair for a passkey fixture.',
        );

        return $key;
    }

    /**
     * @return array{0: string, 1: string} the x and y coordinates, each padded to exactly 32 bytes
     */
    private static function publicKeyCoordinates(\OpenSSLAsymmetricKey $privateKey): array
    {
        $details = openssl_pkey_get_details($privateKey);
        $ellipticCurve = \is_array($details) && \is_array($details['ec'] ?? null) ? $details['ec'] : null;
        $x = $ellipticCurve['x'] ?? null;
        $y = $ellipticCurve['y'] ?? null;

        (\is_string($x) && \is_string($y)) || throw new \RuntimeException(
            'Unable to read the EC P-256 public key coordinates for a passkey fixture.',
        );

        return [self::leftPadded($x), self::leftPadded($y)];
    }

    /**
     * OpenSSL may return a coordinate shorter than the curve width; COSE and WebAuthn need it left-padded with zeros.
     */
    private static function leftPadded(string $coordinate): string
    {
        return str_pad($coordinate, self::EC_COORDINATE_LENGTH_BYTES, "\x00", \STR_PAD_LEFT);
    }

    private static function coseKeyBytes(string $x, string $y): string
    {
        $key = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(self::COSE_KEY_TYPE_EC2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(self::COSE_ALGORITHM_ES256))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(self::COSE_CURVE_P256))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        return (string) $key;
    }

    private static function authenticatorData(
        string $relyingPartyId,
        string $credentialId,
        string $publicKeyCose,
        int $signCount,
        int $flags,
        string $aaguid,
    ): string {
        return hash('sha256', $relyingPartyId, true)
            . \chr($flags)
            . pack('N', $signCount)
            . $aaguid
            . pack('n', \strlen($credentialId))
            . $credentialId
            . $publicKeyCose;
    }

    /**
     * Shared by attestation() ("webauthn.create") and assertion()
     * ("webauthn.get") — the two ceremony types differ only in this one
     * field, per CheckClientDataCollectorType.
     */
    private static function clientDataJson(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * An assertion's authenticatorData is the registration one's prefix —
     * rpIdHash, flags, signCount — with no attested credential data
     * appended (spec §6.1); see DEFAULT_ASSERTION_FLAGS.
     */
    private static function authenticatorDataForAssertion(string $relyingPartyId, int $signCount, int $flags): string
    {
        return hash('sha256', $relyingPartyId, true)
            . \chr($flags)
            . pack('N', $signCount);
    }

    /**
     * Signs authenticatorData plus the SHA-256 of the raw clientDataJSON bytes, never re-encoded JSON. openssl_sign()
     * returns DER, like a real authenticator; CoseSignatureFixer converts it on the verifying side.
     */
    private static function sign(
        string $authenticatorData,
        string $clientDataJson,
        \OpenSSLAsymmetricKey $privateKey,
    ): string {
        $dataToVerify = $authenticatorData . hash('sha256', $clientDataJson, true);

        openssl_sign($dataToVerify, $signature, $privateKey, \OPENSSL_ALGO_SHA256)
            || throw new \RuntimeException('Unable to sign a passkey assertion fixture.');

        /** @var string $signature */
        return $signature;
    }

    /**
     * @param array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle: string} $response
     *
     * @return PasskeyAssertionCredentialPayload
     */
    private static function assertionCredentialPayload(
        PasskeyAttestationFixture $enrolledCredential,
        array $response,
    ): array {
        $id = Base64UrlSafe::encodeUnpadded($enrolledCredential->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => $response,
        ];
    }

    private static function attestationObject(string $authenticatorData): string
    {
        $object = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            // An empty map, which the spec requires; an empty CBOR list would also decode to [] in PHP.
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

        return (string) $object;
    }

    /**
     * @return PasskeyCredentialPayload
     */
    private static function credentialPayload(
        string $credentialId,
        string $clientDataJson,
        string $attestationObject,
    ): array {
        $id = Base64UrlSafe::encodeUnpadded($credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
            ],
        ];
    }
}
