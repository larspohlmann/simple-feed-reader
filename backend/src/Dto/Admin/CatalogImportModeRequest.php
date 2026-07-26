<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Service\Catalog\CatalogImportMode;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogImportModeRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?CatalogImportMode $mode = null,
    ) {
    }
}
