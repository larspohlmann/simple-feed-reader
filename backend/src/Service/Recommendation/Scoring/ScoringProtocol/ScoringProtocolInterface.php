<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\ScoringProtocol;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** How one family of scoring models is asked, keyed in ScoringProtocolResolver's locator by its ScoringProtocol value. */
#[AutoconfigureTag('app.scoring_protocol')]
interface ScoringProtocolInterface
{
    /**
     * @param list<ArticleLineModel> $candidates
     *
     * @return list<list<int>> entry ids per request, in candidate order
     */
    public function pack(ScoringBudgetModel $budget, array $candidates): array;

    /** The request as this protocol sends it, pretty-printed for the run log. */
    public function renderedRequest(ScoringRequestModel $request): string;

    /**
     * @param non-empty-list<ScoringRequestModel> $requests
     *
     * @return list<ScoringOutcomeModel> aligned by index; a failed call is an outcome, never a throw
     */
    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array;
}
