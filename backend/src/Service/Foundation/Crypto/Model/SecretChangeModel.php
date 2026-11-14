<?php

declare(strict_types=1);

namespace App\Service\Crypto\Model;

final readonly class SecretChangeModel
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

    public static function fromSubmitted(?string $secret): self
    {
        return null === $secret ? self::keep() : self::replaceWith($secret);
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
