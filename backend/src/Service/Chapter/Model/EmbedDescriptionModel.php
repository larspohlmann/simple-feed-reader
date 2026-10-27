<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

/** What a provider tells about an embed; '' when it has no description. */
final readonly class EmbedDescriptionModel
{
    public function __construct(
        public ?string $title,
        public ?int $durationSeconds,
        public string $description,
        public ?string $artworkUrl,
    ) {
    }
}
