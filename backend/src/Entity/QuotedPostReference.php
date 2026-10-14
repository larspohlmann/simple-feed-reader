<?php

declare(strict_types=1);

namespace App\Entity;

/** The post a quoted post quotes in turn: shown as one line, never nested again. */
final readonly class QuotedPostReference
{
    public function __construct(
        public string $url,
        public ?string $handle,
    ) {
    }
}
