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
 * Builds a synthetic "attestation: none" WebAuthn registration ceremony
 * entirely in PHP (#624) — no browser, no Chrome DevTools Protocol virtual
 * authenticator.
 *
 * This is possible, and not a shortcut, because of how the "none" attestation
 * format is defined by the spec: the attestationObject is CBOR of
 * `{fmt: "none", attStmt: {}, authData: <bytes>}`, and NOTHING in it is
 * signed. A real authenticator only produces a signature over `attStmt` for
 * formats such as "packed"; "none" carries no signature at all, so there is
 * no private-key ceremony to fake for REGISTRATION — only bytes to assemble
 * in the shape the spec defines:
 *
 *   authData = SHA-256(rpId) . flags . signCount (4 bytes, big-endian)
 *            . AAGUID (16 bytes) . credentialIdLength (2 bytes, big-endian)
 *            . credentialId . COSE public key (a CBOR map)
 *
 * Every value this depends on — relying-party id, origin, challenge,
 * credential id, user handle, sign count — is a parameter, so the
 * origin-mismatch, RP-id-mismatch and tampered-clientDataJSON test cases are
 * one-line variations on a single call rather than a second capture.
 *
 * The EC P-256 key pair minted per fixture is not thrown away: its private
 * key travels on the returned fixture (see PasskeyAttestationFixture)
 * because a later assertion ("login") ceremony fixture needs to sign over
 * the same identity, and a real authenticator would never invent a new key
 * for a credential it already holds.
 *
 * @phpstan-import-type PasskeyCredentialPayload from PasskeyAttestationFixture
 */
final readonly class PasskeyFixtures
{
    private const string OPENSSL_EC_CURVE = 'prime256v1';
    private const int EC_COORDINATE_LENGTH_BYTES = 32;

    /** All-zero: the spec's "no AAGUID assigned" value. Not asserted on by any test in this task. */
    private const string AAGUID = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    /**
     * Public (#624, fix round 2): PasskeyRegistrationTest builds a
     * deliberately UV-cleared fixture with these to prove the ceremony
     * refuses it, since $flags below defaults to requiring UV and a test
     * cannot override a default it cannot name.
     */
    final public const int FLAG_USER_PRESENT = 0x01;
    final public const int FLAG_USER_VERIFIED = 0x04;
    final public const int FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED = 0x40;

    private const int DEFAULT_FLAGS = self::FLAG_USER_PRESENT
        | self::FLAG_USER_VERIFIED
        | self::FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED;

    private const int COSE_KEY_TYPE_EC2 = 2;
    private const int COSE_ALGORITHM_ES256 = -7;
    private const int COSE_CURVE_P256 = 1;

    /**
     * $flags defaults to UP|UV|AT — user verification is required elsewhere
     * in this feature (spec §4.1.1), so a fixture built with the default is
     * "a valid attestation"; one built with FLAG_USER_VERIFIED cleared is
     * the one case that must NOT verify (#624, fix round 2).
     */
    public static function attestation(
        string $relyingPartyId,
        string $origin,
        string $challenge,
        string $credentialId,
        string $userHandle,
        int $signCount = 0,
        int $flags = self::DEFAULT_FLAGS,
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
        );
        $clientDataJson = self::clientDataJson($challenge, $origin);
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
        $ec = \is_array($details) && \is_array($details['ec'] ?? null) ? $details['ec'] : null;
        $x = $ec['x'] ?? null;
        $y = $ec['y'] ?? null;

        (\is_string($x) && \is_string($y)) || throw new \RuntimeException(
            'Unable to read the EC P-256 public key coordinates for a passkey fixture.',
        );

        return [self::leftPadded($x), self::leftPadded($y)];
    }

    /**
     * OpenSSL does not guarantee a coordinate comes back at the curve's full
     * width: a value with leading zero bytes can come back shorter. The COSE
     * and WebAuthn wire formats both require the fixed width, so a short
     * coordinate is zero-padded on the left rather than trusted as-is.
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
    ): string {
        return hash('sha256', $relyingPartyId, true)
            . \chr($flags)
            . pack('N', $signCount)
            . self::AAGUID
            . pack('n', \strlen($credentialId))
            . $credentialId
            . $publicKeyCose;
    }

    private static function clientDataJson(string $challenge, string $origin): string
    {
        return json_encode([
            'type' => 'webauthn.create',
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], \JSON_THROW_ON_ERROR);
    }

    private static function attestationObject(string $authenticatorData): string
    {
        $object = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            // An empty map, not an empty list: NoneAttestationStatementSupport
            // requires attStmt to decode back to `[]`, which an empty CBOR map
            // and an empty CBOR list both normalize to in PHP — but the map is
            // what the spec actually requires here, so that is what is built.
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
