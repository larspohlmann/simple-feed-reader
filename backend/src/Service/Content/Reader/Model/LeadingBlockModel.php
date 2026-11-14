<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

use Dom\Element;

/** A leaf text block with its whitespace-collapsed text, computed once by the walker. */
final readonly class LeadingBlockModel
{
    public function __construct(
        public Element $element,
        public string $text,
    ) {
    }
}
