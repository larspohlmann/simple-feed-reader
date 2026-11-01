<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A normalised URL a link post shares, with the title the reader shares it by. */
final readonly class SharedLinkModel
{
    public function __construct(
        public string $url,
        public string $title,
    ) {
    }
}
