<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CatalogCategoryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Key must be a lowercase slug.')]
        public string $key = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Icon must be a Material Symbol name.')]
        public string $icon = '',
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Color must be a hex value like #ff8800.')]
        public string $color = '#000000',
        public bool $enabled = true,
        /** Locked rows are the admin's: an import will neither overwrite nor delete them. */
        public bool $locked = true,
    ) {
    }
}
