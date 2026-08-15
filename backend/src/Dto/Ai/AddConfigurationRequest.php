<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddConfigurationRequest
{
    public function __construct(
        #[Assert\Length(max: 120)]
        public ?string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $baseUrl,
        /**
         * Optional: a local model server (LM Studio, Ollama, llama.cpp) needs no
         * credential. An empty key means no Authorization header is sent at all.
         */
        #[Assert\Length(max: 512)]
        public string $apiKey,
    ) {
    }
}
