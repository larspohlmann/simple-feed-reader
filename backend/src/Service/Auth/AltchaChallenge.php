<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** The JSON the ALTCHA browser widget consumes verbatim. */
final readonly class AltchaChallenge
{
    public function __construct(
        public string $algorithm,
        public string $challenge,
        public string $salt,
        public string $signature,
        public int $maxNumber,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm,
            'challenge' => $this->challenge,
            'salt' => $this->salt,
            'signature' => $this->signature,
            // The widget's field name is lowercase.
            'maxnumber' => $this->maxNumber,
        ];
    }
}
