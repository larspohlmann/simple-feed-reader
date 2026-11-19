<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class PostCommunity
{
    private function __construct(
        public string $identity,
        public string $url,
    ) {
    }

    public static function fromIdentityAndUrl(string $identity, string $url): self
    {
        return new self(strtolower($identity), $url);
    }

    public function shortName(): string
    {
        return explode('@', $this->identity)[0];
    }
}
