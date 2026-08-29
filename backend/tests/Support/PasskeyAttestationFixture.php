<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A synthetic "attestation: none" WebAuthn registration ceremony built by
 * PasskeyFixtures (#624), together with everything needed to reuse the same
 * credential in a later assertion ("login") ceremony: the private key never
 * left this fixture, exactly as a real authenticator's would not.
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
