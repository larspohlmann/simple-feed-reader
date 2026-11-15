<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** A story's articles as one card shows them: the lead, then the others newest first. */
final readonly class StoryArticlesModel
{
    /** @param list<StoryArticleModel> $others */
    public function __construct(public StoryArticleModel $lead, public array $others)
    {
    }

    public function shareCount(): int
    {
        return array_sum(array_map(
            static fn (StoryArticleModel $article): int => \count($article->shares),
            [$this->lead, ...$this->others],
        ));
    }
}
