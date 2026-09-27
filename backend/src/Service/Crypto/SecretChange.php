<?php

declare(strict_types=1);

namespace App\Service\Crypto;

final readonly class SecretChange
{
    private function __construct(
        private ?string $replacement,
        private bool $removal,
    ) {
    }

    public static function keep(): self
    {
        return new self(null, false);
    }

    public static function replaceWith(string $secret): self
    {
        return new self($secret, false);
    }

    public static function remove(): self
    {
        return new self(null, true);
    }

    public function replacement(): ?string
    {
        return $this->replacement;
    }

    public function isRemoval(): bool
    {
        return $this->removal;
    }
}
