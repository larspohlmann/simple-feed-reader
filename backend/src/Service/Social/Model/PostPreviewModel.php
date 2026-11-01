<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\LinkCardContent;

/** A post as the composer shows it above the text: who wrote it, how it starts, and the link card it carries. */
final readonly class PostPreviewModel
{
    /** @param string|null $authorHandle the handle with its leading "@" */
    public function __construct(
        public ?string $authorName = null,
        public ?string $authorHandle = null,
        public string $excerpt = '',
        public ?LinkCardContent $card = null,
    ) {
    }
}
