<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The one place that decides which engine runs a connection's recommendations. */
final readonly class RecommendationEngineResolver
{
    public function __construct(
        #[AutowireLocator('app.recommendation_engine')]
        private ContainerInterface $engines,
    ) {
    }

    public function kindFor(AiProviderSettings $connection): RecommendationEngineKind
    {
        return RecommendationEngineKind::Llm;
    }

    public function capabilitiesFor(AiProviderSettings $connection): RecommendationEngineCapabilitiesModel
    {
        return $this->kindFor($connection)->capabilities();
    }

    public function engineFor(AiProviderSettings $connection): RecommendationEngineInterface
    {
        $kind = $this->kindFor($connection);
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
