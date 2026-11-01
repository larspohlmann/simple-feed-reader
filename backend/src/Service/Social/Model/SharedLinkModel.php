<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** An article posted as a link: where it lives and what its card shows. */
final readonly class SharedLinkModel
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public ?string $imageUrl,
    ) {
    }
}
