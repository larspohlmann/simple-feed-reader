<?php

declare(strict_types=1);

namespace App\Dto\Tag;

use App\Service\Tag\Model\TagDetailsModel;
use Symfony\Component\Validator\Constraints as Assert;

/** Every field is required: a missing parentId would otherwise read as null and un-parent the tag. */
final readonly class UpdateTagRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 100)]
        public string $name,
        #[Assert\Length(max: 20)]
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Color must be a hex value like #ff8800.')]
        public ?string $color,
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Icon must be a Material Symbol name.')]
        public ?string $icon,
        #[Assert\Positive]
        public ?int $parentId,
    ) {
    }

    public function toDetails(): TagDetailsModel
    {
        return new TagDetailsModel(
            name: $this->name,
            color: $this->color,
            icon: $this->icon,
            parentId: $this->parentId,
        );
    }
}
