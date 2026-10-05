<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Engine;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\RecommendationProfileSource;
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
    /** @return iterable<string, array{?string, RecommendationEngineKind}> */
    public static function models(): iterable
    {
        yield 'the Jev alias' => ['jev-latest', RecommendationEngineKind::Scoring];
        yield 'the preview alias' => ['jev-preview', RecommendationEngineKind::Scoring];
        yield 'a pinned Jev version' => ['jev-1.13.0', RecommendationEngineKind::Scoring];
        yield 'the TypeSafe chat router' => ['typesafe/jev-router', RecommendationEngineKind::Llm];
        yield 'a chat model' => ['gpt-4o', RecommendationEngineKind::Llm];
        yield 'another case, another id' => ['JEV-latest', RecommendationEngineKind::Llm];
        yield 'no model yet' => [null, RecommendationEngineKind::Llm];
    }

    #[DataProvider('models')]
    public function testTheModelIdDecidesTheKind(?string $model, RecommendationEngineKind $kind): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        if (null !== $model) {
            $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-02 09:05:00'));
        }

        self::assertSame($kind, $resolver->kindFor($connection));
    }

    public function testAJevModelIsLabelledJevAndAnLlmModelCarriesNoLabel(): void
    {
        $resolver = new RecommendationEngineResolver(new ServiceLocator([]));

        self::assertSame('Jev', $resolver->labelForModel('jev-latest'));
        self::assertNull($resolver->labelForModel('typesafe/jev-router'));
    }

    public function testTheJevKindWritesNoReasonsSendsNoPromptAndReadsOnlyTheBatchConcurrency(): void
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
        $account = new User('jev-account@example.test', new \DateTimeImmutable('2026-10-02 09:00:00'));
        $account->setActiveAiProviderSettings($this->connection('jev-latest'));

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
            $resolver->capabilitiesFor($this->connection('gpt-4o-mini')),
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

    private function connection(string $model): AiProviderSettings
    {
        $connection = AiProviderSettingsFactory::build(
            new User('engine-resolver@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-10-02 09:05:00'));

        return $connection;
    }
}
