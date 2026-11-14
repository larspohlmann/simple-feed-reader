<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\RerankClient;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;

interface RerankClientInterface
{
    /**
     * Sends every request at once; one outcome per request, aligned by index, a per-call failure carried in its
     * outcome, never thrown.
     *
     * @param non-empty-list<RerankRequestModel> $requests
     *
     * @return list<ScoringOutcomeModel>
     */
    public function rerankMany(ProviderCredentialsModel $credentials, array $requests): array;
}
