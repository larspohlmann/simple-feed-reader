<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Model;

/** Original entry id to its copy in the saved feed. */
final readonly class SavedCopiesModel
{
    /**
     * @param array<int, int> $held    copies the saved feed already held
     * @param array<int, int> $created copies made now
     */
    public function __construct(
        public array $held,
        public array $created,
    ) {
    }
}
