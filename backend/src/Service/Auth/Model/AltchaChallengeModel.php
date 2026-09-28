<?php

declare(strict_types=1);

namespace App\Service\Auth\Model;

final readonly class AltchaChallengeModel
{
    public function __construct(
        public string $algorithm,
        public string $challenge,
        public string $salt,
        public string $signature,
        public int $maxNumber,
    ) {
    }
}
