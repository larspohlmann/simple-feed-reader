<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The address is validated for shape here and normalised in
 * ProviderCredentials; the two are not redundant. This rejects an empty body
 * with a 422 before any outbound call, the other decides the stored form.
 */
final readonly class SaveConnectionRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $baseUrl,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 512)]
        public string $apiKey,
    ) {
    }
}
