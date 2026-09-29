<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A synthetic `attestation: none` registration from PasskeyFixtures, with the private key kept for later assertions,
 * as a real authenticator keeps its own.
 *
 * @phpstan-type PasskeyCredentialPayload array{
 *     id: string,
 *     rawId: string,
 *     type: string,
 *     response: array{clientDataJSON: string, attestationObject: string},
 * }
 */
final readonly class PasskeyAttestationFixture
{
    /**
     * @param PasskeyCredentialPayload $credential
     */
    public function __construct(
        public array $credential,
        public string $challenge,
        public string $credentialId,
        public string $userHandle,
        public string $relyingPartyId,
        public string $origin,
        public \OpenSSLAsymmetricKey $privateKey,
    ) {
    }
}
