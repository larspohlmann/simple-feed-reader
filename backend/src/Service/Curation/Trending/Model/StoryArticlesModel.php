<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** A story's articles as one card shows them: the lead, then the others newest first. */
final readonly class StoryArticlesModel
{
    /** @param list<StoryArticleModel> $others */
    public function __construct(public StoryArticleModel $lead, public array $others)
    {
    }

    /** The lead's row, its duplicates the other articles' rows. */
    public function leadRow(): EntryListRow
    {
        return $this->lead->row->withDuplicates(array_map(
            static fn (StoryArticleModel $article): EntryListRow => $article->row,
            $this->others,
        ));
    }

    public function shareCount(): int
    {
        return array_sum(array_map(
            static fn (StoryArticleModel $article): int => \count($article->shares),
            [$this->lead, ...$this->others],
        ));
    }
}
