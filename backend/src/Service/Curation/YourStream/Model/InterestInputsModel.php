<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

/** What one account's word list is built from. */
final readonly class InterestInputsModel
{
    /**
     * @param list<StreamEntryModel> $articles
     * @param list<string>           $searchTerms
     */
    public function __construct(public int $userId, public array $articles, public array $searchTerms)
    {
    }

    public function fingerprint(): string
    {
        $articleIds = array_map(static fn (StreamEntryModel $article): int => $article->id, $this->articles);
        sort($articleIds);
        $searchTerms = $this->searchTerms;
        sort($searchTerms);

        return sha1(json_encode([$articleIds, $searchTerms], \JSON_THROW_ON_ERROR));
    }
}
