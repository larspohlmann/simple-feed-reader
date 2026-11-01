<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A post as the composer shows it above the text: who wrote it and how it starts. */
final readonly class PostPreviewModel
{
    /** @param string|null $authorHandle the handle with its leading "@" */
    public function __construct(
        public ?string $authorName = null,
        public ?string $authorHandle = null,
        public string $excerpt = '',
    ) {
    }
}
