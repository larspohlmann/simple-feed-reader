<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Catalog\CatalogImportMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The document arrives as OPML *text* inside an ordinary JSON body: the admin UI
 * reads the chosen file and posts its contents verbatim, so no multipart
 * handling is needed and the admin API stays pure JSON. CatalogDocument does the
 * real validation.
 */
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
