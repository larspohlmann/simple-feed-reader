<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\ScriptedRecommendationEngine;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RecommendationEngineResolverTest extends TestCase
{
    /** `typesafe/jev-router` is a chat-completions router, so it stays an LLM connection after #1345 too. */
    public function testEveryConnectionIsAnLlmConnection(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        self::assertSame(RecommendationEngineKind::Llm, $resolver->kindFor($this->connection('gpt-4o-mini')));
        self::assertSame(RecommendationEngineKind::Llm, $resolver->kindFor($this->connection('typesafe/jev-router')));
    }

    public function testTheLlmKindWritesReasonsAndReadsEveryTuningFieldInTheirOrder(): void
    {
        $capabilities = RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm);

        self::assertTrue($capabilities->writesReasons);
        self::assertSame(
            [
                RecommendationTuningField::ContextWindow,
                RecommendationTuningField::BatchSize,
                RecommendationTuningField::SuppressReasoning,
                RecommendationTuningField::SlowModel,
                RecommendationTuningField::MaxBatchSize,
                RecommendationTuningField::BatchConcurrency,
            ],
            $capabilities->tuningFields,
        );
    }

    public function testCapabilitiesAreTheConnectionsKindsAndBuildNoEngine(): void
    {
        $locator = $this->createMock(ContainerInterface::class);
        $locator->expects($this->never())->method('get');
        $locator->expects($this->never())->method('has');
        $resolver = new RecommendationEngineResolver($locator);

        self::assertEquals(
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm),
            $resolver->capabilitiesFor($this->connection('gpt-4o-mini')),
        );
    }

    public function testTheEngineComesFromTheLocatorUnderItsKindsValue(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $resolver = new RecommendationEngineResolver(new ServiceLocator(['llm' => static fn () => $engine]));

        self::assertSame($engine, $resolver->engineFor($this->connection('gpt-4o-mini')));
    }

    public function testAKindWithoutAnEngineIsAWiringError(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        try {
            $resolver->engineFor($this->connection('gpt-4o-mini'));
            self::fail('A missing engine must not resolve.');
        } catch (\LogicException $exception) {
            self::assertSame('No recommendation engine is wired for "llm".', $exception->getMessage());
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }

    private function connection(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel($model, new \DateTimeImmutable('2026-10-02 09:05:00'), null);

        return $connection;
    }
}
