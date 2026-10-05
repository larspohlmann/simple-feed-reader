<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Llm\LlmRecommendationEngine;
use App\Service\Recommendation\Scoring\ScoringRecommendationEngine;
use App\Tests\Support\AiProviderSettingsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The resolver's unit tests hand-build the locator; only the compiled container proves the tag and the index. */
final class RecommendationEngineWiringTest extends KernelTestCase
{
    public function testTheContainerResolvesAConnectionToTheLlmEngine(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $resolver);
        $connection = AiProviderSettingsFactory::build(
            new User('engine-wiring@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel(
            new ModelDescriptor('gpt-4o-mini', null),
            new \DateTimeImmutable('2026-10-02 09:05:00'),
        );

        self::assertInstanceOf(LlmRecommendationEngine::class, $resolver->engineOf($resolver->kindFor($connection)));
    }

    public function testTheContainerResolvesAScoringConnectionToTheScoringEngine(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $resolver);
        $connection = AiProviderSettingsFactory::build(
            new User('engine-wiring-jev@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel(
            new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-02 09:05:00'),
        );

        self::assertInstanceOf(
            ScoringRecommendationEngine::class,
            $resolver->engineOf($resolver->kindFor($connection)),
        );
    }
}
