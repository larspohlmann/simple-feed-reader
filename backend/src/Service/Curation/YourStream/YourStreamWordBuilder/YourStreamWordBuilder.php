<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\YourStreamWordBuilder;

use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Model\InterestInputsModel;
use App\Service\Curation\YourStream\Pass\InterestTally;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;
use App\Service\Curation\YourStream\SearchTermStems;
use App\Service\Curation\YourStream\YourStreamBackground;
use App\Service\Curation\YourStream\YourStreamWordSelection;

final readonly class YourStreamWordBuilder implements YourStreamWordBuilderInterface
{
    public function __construct(
        private YourStreamSourceRepository $sources,
        private SearchTermStems $searchTermStems,
        private YourStreamBackground $background,
        private YourStreamWordSelection $selection,
    ) {
    }

    public function buildWords(InterestInputsModel $inputs, RaritySweepPass $pass): array
    {
        $interests = new InterestTally();
        $this->recordArticles($interests, $inputs, $pass);
        $this->recordSearchTerms($interests, $inputs);
        $background = $this->background->collectBackground($inputs->userId, $pass, $interests->collectWantedStems());

        return $this->selection->pickWords($interests, $background);
    }

    private function recordArticles(InterestTally $interests, InterestInputsModel $inputs, RaritySweepPass $pass): void
    {
        foreach ($this->sources->findEntries($inputs->articleIds) as $article) {
            $interests->recordArticle($pass->stemming->collectStemsWithWords($article));
        }
    }

    private function recordSearchTerms(InterestTally $interests, InterestInputsModel $inputs): void
    {
        $languages = [...$this->sources->findFeedLanguages($inputs->userId), null];
        foreach ($inputs->searchTerms as $typed) {
            $term = $this->searchTermStems->buildTerm($typed);
            $articles = $term->isSingleWord ? YourStreamWordSelection::SEARCH_ARTICLES : 1;
            foreach ($languages as $language) {
                $stemShares = $this->searchTermStems->collectStems($term, $language);
                $interests->recordSearch($language, $stemShares, $typed, $articles);
            }
        }
    }
}
