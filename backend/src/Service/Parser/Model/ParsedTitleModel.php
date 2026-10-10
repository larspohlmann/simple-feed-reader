<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

final readonly class ParsedTitleModel
{
    private const string UNTITLED = '(untitled)';

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

    public static function untitled(): self
    {
        return new self(self::UNTITLED, false);
    }

    public static function untitledPost(): self
    {
        return new self(self::UNTITLED, true);
    }
}
