<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\SystemOneClient;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;

interface SystemOneClientInterface
{
    /**
     * Sends every request at once; one outcome per request, aligned by index. A per-call failure is carried in its
     * outcome, never thrown, so it cannot discard a sibling's answer.
     *
     * @param non-empty-list<SystemOneRequestModel> $requests
     *
     * @return list<SystemOneOutcomeModel>
     */
    public function evaluateMany(ProviderCredentialsModel $credentials, array $requests): array;
}
