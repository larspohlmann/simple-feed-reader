<?php

declare(strict_types=1);

namespace App\Dto\Passkey;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of a passkey registration ("attestation") ceremony's completion
 * (#624): the opaque handle PasskeyChallengeStore issued alongside the
 * options, the browser's raw `navigator.credentials.create()` response, and
 * the label the account chooses for this credential.
 *
 * $credential is intentionally untyped beyond "an array": it is opaque,
 * client-supplied WebAuthn wire data that AttestationVerifier hands straight
 * to the WebAuthn library's own deserializer, which is where its shape is
 * actually enforced. Validating it twice, once loosely here and once for
 * real in the library, would only risk the two disagreeing.
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
}
