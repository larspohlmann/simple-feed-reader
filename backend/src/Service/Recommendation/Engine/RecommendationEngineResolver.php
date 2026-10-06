<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The one place that decides which engine runs a connection's recommendations. */
final readonly class RecommendationEngineResolver
{
    private const RecommendationEngineKind DEFAULT_KIND = RecommendationEngineKind::Llm;

    public function __construct(
        #[AutowireLocator('app.recommendation_engine')]
        private ContainerInterface $engines,
    ) {
    }

    public function kindFor(AiProviderSettings $connection): RecommendationEngineKind
    {
        return $connection->getModelKind();
    }

    public function capabilitiesFor(AiProviderSettings $connection): RecommendationEngineCapabilitiesModel
    {
        return RecommendationEngineCapabilitiesModel::of($this->kindFor($connection));
    }

    /** An account with no active connection reads as the default kind, as a model-less connection does. */
    public function capabilitiesForAccount(User $user): RecommendationEngineCapabilitiesModel
    {
        $connection = $user->getActiveAiProviderSettings();

        return null === $connection
            ? RecommendationEngineCapabilitiesModel::of(self::DEFAULT_KIND)
            : $this->capabilitiesFor($connection);
    }

    public function engineOf(RecommendationEngineKind $kind): RecommendationEngineInterface
    {
        try {
            $engine = $this->engines->get($kind->value);
        } catch (ContainerExceptionInterface $exception) {
            throw new \LogicException(
                sprintf('No recommendation engine is wired for "%s".', $kind->value),
                previous: $exception,
            );
        }
        \assert($engine instanceof RecommendationEngineInterface);

        return $engine;
    }
}
