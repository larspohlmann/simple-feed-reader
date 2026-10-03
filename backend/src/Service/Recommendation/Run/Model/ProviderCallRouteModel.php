<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Entity\AiProviderSettings;
use App\Service\Ai\Model\RetryPlanModel;

/** Where one provider call goes and how long it may wait out a rate limit. */
final readonly class ProviderCallRouteModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public AiProviderSettings $connection,
        public RetryPlanModel $retryPlan,
    ) {
    }
}
