<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** One article of a story: the row that shows it, and the posts that share it, newest first, each post once. */
final readonly class StoryArticleModel
{
    /** @param list<EntryListRow> $shares */
    public function __construct(public EntryListRow $row, public array $shares)
    {
    }

    public function isFromAnOutlet(): bool
    {
        return $this->row->entry->getFeed()->getNetwork() === null;
    }

    public function newestShareTime(): ?\DateTimeImmutable
    {
        return ($this->shares[0] ?? null)?->entry->getEffectiveDate();
    }
}
