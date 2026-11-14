<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\SystemOneClient;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;

interface SystemOneClientInterface
{
    /**
     * Sends every request at once; one outcome per request, aligned by index. A per-call failure is carried in its
     * outcome, never thrown, so it cannot discard a sibling's answer.
     *
     * @param non-empty-list<SystemOneRequestModel> $requests
     *
     * @return list<ScoringOutcomeModel>
     */
    public function evaluateMany(ProviderCredentialsModel $credentials, array $requests): array;
}
