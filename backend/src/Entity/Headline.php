<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class Headline
{
    private function __construct(
        public string $text,
        public bool $derived,
    ) {
    }

    public static function fromFeed(string $text): self
    {
        return new self($text, false);
    }

    public static function derived(string $text): self
    {
        return new self($text, true);
    }

    public static function untitledPost(): self
    {
        return new self('', true);
    }

    public function isUntitledPost(): bool
    {
        return $this->derived && $this->text === '';
    }
}
