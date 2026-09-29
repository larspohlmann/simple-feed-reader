<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Catalog\Model\CatalogImportMode;
use Symfony\Component\Validator\Constraints as Assert;

/** OPML text in a JSON body, so the admin API stays pure JSON; CatalogDocument does the real validation. */
final readonly class CatalogImportRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?CatalogImportMode $mode = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 2_000_000)]
        public string $document = '',
    ) {
    }
}
