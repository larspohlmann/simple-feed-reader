<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\ScoringProtocol;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How one family of scoring models is asked, keyed in ScoringProtocolResolver's locator by its ScoringProtocol value.
 *
 * @template TWorded of object a request in this protocol's own words
 */
#[AutoconfigureTag('app.scoring_protocol')]
interface ScoringProtocolInterface
{
    /** What one request may carry for a model with this context window. */
    public function budget(int $contextWindowTokens): ScoringBudgetModel;

    /**
     * @param list<ArticleLineModel> $candidates
     *
     * @return list<list<int>> entry ids per request, in candidate order
     */
    public function pack(ScoringBudgetModel $budget, array $candidates): array;

    /** @return TWorded */
    public function word(ScoringRequestModel $request): object;

    /**
     * The request as this protocol sends it, pretty-printed for the run log.
     *
     * @param TWorded $request
     */
    public function renderedRequest(object $request): string;

    /**
     * @param non-empty-list<TWorded> $requests
     *
     * @return list<ScoringOutcomeModel> aligned by index; a failed call is an outcome, never a throw
     */
    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array;
}
