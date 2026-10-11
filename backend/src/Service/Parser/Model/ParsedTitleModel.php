<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Entity\EntryHeadline;

final readonly class ParsedTitleModel
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
        return new self(EntryHeadline::UNTITLED, true);
    }
}
