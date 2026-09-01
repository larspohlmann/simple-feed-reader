<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;

/** A leaf text block with its whitespace-collapsed text, computed once by the walker. */
final readonly class LeadingBlock
{
    public function __construct(
        public Element $element,
        public string $text,
    ) {
    }
}
