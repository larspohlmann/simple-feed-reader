<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\User;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Jev\JevRecommendationEngine;
use App\Service\Recommendation\Llm\LlmRecommendationEngine;
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
        $connection->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-10-02 09:05:00'), null);

        self::assertInstanceOf(LlmRecommendationEngine::class, $resolver->engineOf($resolver->kindFor($connection)));
    }

    public function testTheContainerResolvesAJevConnectionToTheJevEngine(): void
    {
        self::bootKernel();
        $resolver = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $resolver);
        $connection = AiProviderSettingsFactory::build(
            new User('engine-wiring-jev@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel('jev-latest', new \DateTimeImmutable('2026-10-02 09:05:00'), 64_000);

        self::assertInstanceOf(JevRecommendationEngine::class, $resolver->engineOf($resolver->kindFor($connection)));
    }
}
