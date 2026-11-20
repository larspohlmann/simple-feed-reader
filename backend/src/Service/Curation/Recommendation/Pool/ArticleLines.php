<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Pool;

use App\Repository\LinkCardRepository;
use App\Repository\TitledEntry;
use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;

/** Turns entries into For you lines, each knowing the story its social post shares. */
final readonly class ArticleLines
{
    public function __construct(private LinkCardRepository $linkCards)
    {
    }

    /**
     * @param list<TitledEntry> $titled
     *
     * @return list<ArticleLineModel> in input order
     */
    public function loadLines(array $titled): array
    {
        $stories = $this->linkCards->storyLinksByEntryIds(array_values(array_map(
            static fn (TitledEntry $each): int => $each->entry->requireId(),
            array_filter($titled, static fn (TitledEntry $each): bool => !$each->entry->getFeed()->isFromAnOutlet()),
        )));

        return array_map(
            static fn (TitledEntry $each): ArticleLineModel => ArticleLineModel::fromTitledEntry(
                $each,
                $stories[$each->entry->requireId()] ?? null,
            ),
            $titled,
        );
    }
}
