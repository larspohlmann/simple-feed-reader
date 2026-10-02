<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Factory;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Profile\ProfileConnectionResolver;
use App\Service\Recommendation\Run\Model\BorrowedProfileModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;

/** What one tick reads about its account, its engine included, decided once before the tick does anything. */
final readonly class TickContextFactory
{
    public function __construct(
        private AiProviderConfigurator $configurator,
        private RecommendationSettingsResolver $settingsResolver,
        private RecommendationEngineResolver $engines,
        private ProfileConnectionResolver $profileConnections,
    ) {
    }

    /** @throws AiNotConfiguredException when the account has no active connection with a model */
    public function create(RecommendationRun $run, TickDriver $driver): TickContext
    {
        $user = $run->getUser();
        $connection = $this->activeConnection($user);
        $settings = $this->settingsResolver->forAccount($user);
        $tick = new TickContext(
            $run,
            $connection,
            $this->engines->kindFor($connection),
            $settings->forConnection($connection),
            $driver,
        );

        $profileConnection = $this->profileConnections->borrowedFor($connection);

        return null === $profileConnection
            ? $tick
            : $tick->borrowingProfileFrom(new BorrowedProfileModel(
                $profileConnection,
                $this->engines->kindFor($profileConnection),
                $settings->forConnection($profileConnection),
            ));
    }

    private function activeConnection(User $user): AiProviderSettings
    {
        $connection = $this->configurator->requireConfiguration($user);
        if (!$connection->hasModel()) {
            throw new AiNotConfiguredException('No model is chosen.');
        }

        return $connection;
    }
}
