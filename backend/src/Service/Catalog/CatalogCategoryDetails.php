<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/** A catalog category as the admin edits it. The key is set once, on create. */
final readonly class CatalogCategoryDetails
{
    public function __construct(
        public string $key,
        public string $name,
        public string $icon,
        public string $color = '#000000',
        public bool $enabled = true,
        public bool $locked = true,
    ) {
    }
}
