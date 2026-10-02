<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Factory;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationProfileSource;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Profile\ProfileConnectionResolver;
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

        return $this->withBorrowedProfile(new TickContext(
            $run,
            $connection,
            $this->engines->kindFor($connection),
            $this->settingsResolver->forUser($user),
            $driver,
        ));
    }

    private function withBorrowedProfile(TickContext $tick): TickContext
    {
        $borrows = RecommendationProfileSource::Borrowed
            === RecommendationEngineCapabilitiesModel::of($tick->engineKind)->profileSource;
        $profileConnection = $borrows ? $this->profileConnections->findUsableFor($tick->run->getUser()) : null;
        if (null === $profileConnection) {
            return $tick;
        }

        return $tick->borrowingProfileFrom(new TickContext(
            $tick->run,
            $profileConnection,
            $this->engines->kindFor($profileConnection),
            $this->settingsResolver->forConnection($profileConnection),
            $tick->driver,
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
