<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\RecommendationProfileSource;
use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\ScriptedRecommendationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RecommendationEngineResolverTest extends TestCase
{
    /** @return iterable<string, array{?ModelDescriptor, RecommendationEngineKind}> */
    public static function chosenModels(): iterable
    {
        yield 'a scoring model whose id names no Jev' => [
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            RecommendationEngineKind::Scoring,
        ];
        yield 'an LLM whose id starts like Jev' => [
            new ModelDescriptor('jev-latest', 128_000),
            RecommendationEngineKind::Llm,
        ];
        yield 'no model yet' => [null, RecommendationEngineKind::Llm];
    }

    #[DataProvider('chosenModels')]
    public function testTheStoredKindDecidesTheEngineNeverTheModelId(
        ?ModelDescriptor $model,
        RecommendationEngineKind $kind,
    ): void {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        if (null !== $model) {
            $connection->chooseModel($model, new \DateTimeImmutable('2026-10-02 09:05:00'));
        }

        self::assertSame($kind, $resolver->kindFor($connection));
    }

    public function testTheScoringKindWritesNoReasonsSendsNoPromptAndReadsOnlyTheBatchConcurrency(): void
    {
        $capabilities = RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Scoring);

        self::assertFalse($capabilities->writesReasons);
        self::assertFalse($capabilities->sendsPrompt);
        self::assertSame(RecommendationProfileSource::Borrowed, $capabilities->profileSource);
        self::assertSame([RecommendationTuningField::BatchConcurrency], $capabilities->tuningFields);
    }

    public function testAnAccountsCapabilitiesAreItsActiveConnections(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $account = new User('scoring-account@example.test', new \DateTimeImmutable('2026-10-02 09:00:00'));
        $account->setActiveAiProviderSettings(
            $this->connection(new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne)),
        );

        self::assertEquals(
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Scoring),
            $resolver->capabilitiesForAccount($account),
        );
    }

    public function testTheLlmKindWritesReasonsSendsAPromptAndReadsEveryTuningFieldInTheirOrder(): void
    {
        $capabilities = RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm);

        self::assertTrue($capabilities->writesReasons);
        self::assertTrue($capabilities->sendsPrompt);
        self::assertSame(RecommendationProfileSource::Own, $capabilities->profileSource);
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
            $resolver->capabilitiesFor($this->connection(new ModelDescriptor('gpt-4o-mini', null))),
        );
    }

    /** Reads as the LLM, the kind a connection without a model resolves to: the unconfigured payload stays as it was. */
    public function testAnAccountWithoutAnActiveConnectionReadsAsTheLlm(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $account = new User('no-connection@example.test', new \DateTimeImmutable('2026-10-02 09:00:00'));

        self::assertEquals(
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm),
            $resolver->capabilitiesForAccount($account),
        );
    }

    public function testTheEngineComesFromTheLocatorUnderItsKindsValue(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $resolver = new RecommendationEngineResolver(new ServiceLocator(['llm' => static fn () => $engine]));

        self::assertSame($engine, $resolver->engineOf(RecommendationEngineKind::Llm));
    }

    public function testAKindWithoutAnEngineIsAWiringError(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        try {
            $resolver->engineOf(RecommendationEngineKind::Llm);
            self::fail('A missing engine must not resolve.');
        } catch (\LogicException $exception) {
            self::assertSame('No recommendation engine is wired for "llm".', $exception->getMessage());
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception->getPrevious());
        }
    }

    private function connection(ModelDescriptor $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel($model, new \DateTimeImmutable('2026-10-02 09:05:00'));

        return $connection;
    }
}
