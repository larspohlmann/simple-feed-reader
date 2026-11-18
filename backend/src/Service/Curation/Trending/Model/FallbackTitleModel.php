<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class FallbackTitleModel
{
    /** @param ?int $entryId null for a link card's title */
    public function __construct(public ?int $entryId, public string $title)
    {
    }
}
