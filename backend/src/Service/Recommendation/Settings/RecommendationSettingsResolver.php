<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings;

use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Pass\AccountRecommendationSettings;

/** The settings every recommendation service reads, against the account's active AI provider unless told another. */
final readonly class RecommendationSettingsResolver
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
    ) {
    }

    public function forUser(User $user): EffectiveRecommendationSettingsModel
    {
        return $this->forAccount($user)->forConnection($user->getActiveAiProviderSettings());
    }

    /** The account's row read once, for a caller that resolves it against more than one connection. */
    public function forAccount(User $user): AccountRecommendationSettings
    {
        return new AccountRecommendationSettings($this->settings->findForUser($user));
    }
}
