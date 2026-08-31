<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/** One anchor of the cleaned article: where it points and what it reads as. */
final readonly class BodyLink
{
    public function __construct(
        public string $href,
        public string $text,
    ) {
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->href, \PHP_URL_HOST));
    }
}
