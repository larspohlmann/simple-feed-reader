<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** A story's row the reader may see, with the article it is about: its own for an outlet, its card's for a post. */
final readonly class ShownRowModel
{
    public function __construct(public EntryListRow $row, public string $articleKey)
    {
    }

    public function isFromAnOutlet(): bool
    {
        return $this->row->entry->getFeed()->getNetwork() === null;
    }
}
