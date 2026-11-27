<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\InterestTermRun;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Service\Curation\Ai\Model\RetryPlanModel;
use App\Service\Curation\Recommendation\Run\Model\ProviderCallRouteModel;

/** What one interest-term extraction reads about its account, read once before the call is made. */
final readonly class InterestTermPass
{
    /**
     * @param list<FeedLanguage> $languages
     *
     * @noinspection AutowireWrongClass Built with new, never autowired
     */
    public function __construct(
        public User $user,
        public AiProviderSettings $connection,
        public InterestTermRun $run,
        public string $profileText,
        public array $languages,
    ) {
    }

    public function callRoute(): ProviderCallRouteModel
    {
        return new ProviderCallRouteModel($this->connection, RetryPlanModel::deferring());
    }
}
