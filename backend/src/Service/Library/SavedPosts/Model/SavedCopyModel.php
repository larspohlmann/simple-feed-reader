<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Model;

use App\Entity\EntryAuthor;

final readonly class SavedCopyModel
{
    /** @param EntryAuthor|null $poster null keeps each entry's author; its avatar only fills a missing one */
    public function __construct(
        public int $savedFeedId,
        public ?EntryAuthor $poster,
    ) {
    }
}
