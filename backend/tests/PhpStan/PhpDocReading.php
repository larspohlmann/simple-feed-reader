<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** What a comment line says beyond its PHPDoc type, or how many brackets its type leaves open for the next line. */
final readonly class PhpDocReading
{
    private function __construct(public string $description, public int $openBrackets)
    {
    }

    public static function closed(string $description): self
    {
        return new self($description, 0);
    }

    public static function open(int $openBrackets): self
    {
        return new self('', $openBrackets);
    }

    public function isOpen(): bool
    {
        return $this->openBrackets > 0;
    }

    public function isProse(): bool
    {
        return !$this->isOpen() && '' !== $this->description;
    }
}
