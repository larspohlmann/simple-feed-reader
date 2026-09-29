<?php

declare(strict_types=1);

namespace App\Dto\Passkey;

use App\Service\Passkey\Model\PasskeyAttestationModel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The completion of a passkey registration. `$credential` stays an untyped array on purpose: it is opaque WebAuthn
 * wire data, and AttestationVerifier hands it to the library's deserializer, which is where its shape is enforced.
 */
final readonly class RegisterPasskeyRequest
{
    /**
     * @param array<string, mixed> $credential
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $handle = '',
        #[Assert\NotBlank]
        public array $credential = [],
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $label = '',
    ) {
    }

    public function toAttestation(): PasskeyAttestationModel
    {
        return new PasskeyAttestationModel(handle: $this->handle, credential: $this->credential, label: $this->label);
    }
}
