<?php

declare(strict_types=1);

namespace App\Dto\Opml;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpmlPreviewRequest
{
    public function __construct(
        public string $opml = '',
        #[Assert\Length(max: 100)]
        public ?string $parentTagName = null,
    ) {
    }
}
