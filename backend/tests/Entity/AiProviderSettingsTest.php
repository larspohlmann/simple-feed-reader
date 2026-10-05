<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiProviderSettingsTest extends TestCase
{
    private function sealed(string $ciphertext = 'Y2lwaGVy'): SealedSecret
    {
        return new SealedSecret($ciphertext, 'bm9uY2U=', 'c2FsdA==', 1);
    }

    private function settings(?string $name = null): AiProviderSettings
    {
        return new AiProviderSettings(
            new User('reader@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            $name,
            'https://api.example.test/v1',
            $this->sealed(),
            'cdef',
            new \DateTimeImmutable('2026-08-06 09:30:00'),
        );
    }

    private function connectionOn(string $model): AiProviderSettings
    {
        $connection = $this->settings();
        $connection->chooseModel(new ModelDescriptor($model, 32768), new \DateTimeImmutable('2026-10-06 08:00:00'));

        return $connection;
    }

    /** @return iterable<string, array{bool}> */
    public static function bothSettings(): iterable
    {
        yield 'turned on' => [true];
        yield 'turned off' => [false];
    }

    public function testANewRowCarriesTheNameItWasGiven(): void
    {
        self::assertSame('Work OpenAI', $this->settings('Work OpenAI')->getName());
    }

    public function testANewRowCarriesNoNameByDefault(): void
    {
        self::assertNull($this->settings()->getName());
    }

    public function testRenamingRoundTripsTheNewName(): void
    {
        $settings = $this->settings('Work OpenAI');

        $settings->rename('Personal OpenRouter');

        self::assertSame('Personal OpenRouter', $settings->getName());
    }

    public function testRenamingToNullClearsTheName(): void
    {
        $settings = $this->settings('Work OpenAI');

        $settings->rename(null);

        self::assertNull($settings->getName());
    }

    public function testANewRowCarriesNoModelYet(): void
    {
        $settings = $this->settings();

        self::assertFalse($settings->hasModel());
        self::assertNull($settings->getModel());
    }

    public function testANewRowRecordsTheVerificationThatCreatedIt(): void
    {
        self::assertEquals(
            new \DateTimeImmutable('2026-08-06 09:30:00'),
            $this->settings()->getVerifiedAt(),
        );
    }

    public function testChoosingAModelStampsTheVerificationTime(): void
    {
        $settings = $this->settings();
        $verifiedAt = new \DateTimeImmutable('2026-08-06 10:00:00');

        $settings->chooseModel(new ModelDescriptor('gpt-4o-mini', 128000), $verifiedAt);

        self::assertTrue($settings->hasModel());
        self::assertSame('gpt-4o-mini', $settings->getModel());
        self::assertEquals($verifiedAt, $settings->getVerifiedAt());
    }

    public function testChoosingAModelRecordsTheContextWindowTheProviderReported(): void
    {
        $settings = $this->settings();

        $settings->chooseModel(
            new ModelDescriptor('gpt-4o-mini', 128000),
            new \DateTimeImmutable('2026-08-06 10:00:00'),
        );

        self::assertSame(128000, $settings->getModelContextWindow());
    }

    public function testAModelChosenWithoutAReportedContextWindowLeavesItNull(): void
    {
        $settings = $this->settings();

        $settings->chooseModel(new ModelDescriptor('gpt-4o-mini', null), new \DateTimeImmutable('2026-08-06 10:00:00'));

        self::assertNull($settings->getModelContextWindow());
    }

    public function testReplacingTheConnectionDropsTheChosenModel(): void
    {
        $settings = $this->settings();
        $settings->chooseModel(
            new ModelDescriptor('gpt-4o-mini', 128000),
            new \DateTimeImmutable('2026-08-06 10:00:00'),
        );

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-08-06 11:00:00'),
        );

        self::assertFalse($settings->hasModel());
        self::assertNull($settings->getModelContextWindow());
        self::assertSame('https://other.example.test/v1', $settings->getBaseUrl());
        self::assertSame('wxyz', $settings->getApiKeyHint());
        self::assertSame('b3RoZXI=', $settings->getSealedSecret()->ciphertext);
    }

    public function testANewRowSuppressesReasoningByDefault(): void
    {
        self::assertTrue($this->settings()->suppressesReasoning());
    }

    public function testSettingSuppressReasoningRoundTrips(): void
    {
        $settings = $this->settings();

        $settings->setSuppressReasoning(false);

        self::assertFalse($settings->suppressesReasoning());
    }

    public function testReplacingTheConnectionKeepsTheReasoningPreference(): void
    {
        $settings = $this->settings();
        $settings->setSuppressReasoning(false);

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-08-06 11:00:00'),
        );

        self::assertFalse($settings->suppressesReasoning());
    }

    public function testBatchConcurrencyDefaultsToOne(): void
    {
        $settings = $this->settings();
        self::assertSame(1, $settings->getRunTuning()->batchConcurrency());
    }

    public function testSetBatchConcurrencyIsReadBack(): void
    {
        $settings = $this->settings();
        $settings->setBatchConcurrency(3);
        self::assertSame(3, $settings->getRunTuning()->batchConcurrency());
    }

    public function testReplacingTheConnectionKeepsTheBatchConcurrency(): void
    {
        $settings = $this->settings();
        $settings->setBatchConcurrency(3);

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-08-06 11:00:00'),
        );

        self::assertSame(3, $settings->getRunTuning()->batchConcurrency());
    }

    public function testANewRowIsNotSlowByDefault(): void
    {
        self::assertFalse($this->settings()->isSlowModel());
    }

    public function testSetSlowModelIsReadBack(): void
    {
        $settings = $this->settings();
        $settings->setSlowModel(true);
        self::assertTrue($settings->isSlowModel());
    }

    public function testANewRowTakesTheDefaultMaxBatchSize(): void
    {
        self::assertNull($this->settings()->getRunTuning()->maxBatchSize());
    }

    public function testSetMaxBatchSizeIsReadBack(): void
    {
        $settings = $this->settings();
        $settings->setMaxBatchSize(25);
        self::assertSame(25, $settings->getRunTuning()->maxBatchSize());
    }

    public function testSetMaxBatchSizeBackToNullRestoresTheDefault(): void
    {
        $settings = $this->settings();
        $settings->setMaxBatchSize(25);

        $settings->setMaxBatchSize(null);

        self::assertNull($settings->getRunTuning()->maxBatchSize());
    }

    public function testReplacingTheConnectionKeepsTheMaxBatchSize(): void
    {
        $settings = $this->settings();
        $settings->setMaxBatchSize(25);

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-08-06 11:00:00'),
        );

        self::assertSame(25, $settings->getRunTuning()->maxBatchSize());
    }

    public function testAFreshConnectionRefusesNothing(): void
    {
        self::assertFalse($this->connectionOn('model-a')->refusesSuppressedReasoning());
    }

    public function testAConnectionWithoutAModelRefusesNothing(): void
    {
        self::assertFalse($this->settings()->refusesSuppressedReasoning());
    }

    public function testARecordedRefusalHoldsForTheModelThatRefused(): void
    {
        $connection = $this->connectionOn('model-a');

        $connection->recordSuppressionRefused();

        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    public function testAnotherModelOnTheConnectionIsNotTheOneThatRefused(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();

        $connection->chooseModel(new ModelDescriptor('model-b', 32768), new \DateTimeImmutable('2026-10-06 09:00:00'));

        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testSwitchingBackToTheModelThatRefusedRemembersIt(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();
        $connection->chooseModel(new ModelDescriptor('model-b', 32768), new \DateTimeImmutable('2026-10-06 09:00:00'));

        $connection->chooseModel(new ModelDescriptor('model-a', 32768), new \DateTimeImmutable('2026-10-06 09:01:00'));

        self::assertTrue($connection->refusesSuppressedReasoning());
    }

    #[DataProvider('bothSettings')]
    public function testChangingTheSettingForgetsTheRefusal(bool $suppressReasoning): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();

        $connection->setSuppressReasoning($suppressReasoning);

        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testAConnectionWithoutAModelCannotRecordARefusal(): void
    {
        $this->expectException(\LogicException::class);

        $this->settings()->recordSuppressionRefused();
    }

    public function testReplacingTheConnectionForgetsTheRefusal(): void
    {
        $connection = $this->connectionOn('model-a');
        $connection->recordSuppressionRefused();

        $connection->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-10-06 09:00:00'),
        );
        $connection->chooseModel(new ModelDescriptor('model-a', 32768), new \DateTimeImmutable('2026-10-06 09:01:00'));

        self::assertFalse($connection->refusesSuppressedReasoning());
    }

    public function testChoosingAScoringModelStoresItsKindProtocolAndWindow(): void
    {
        $settings = $this->settings();

        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );

        self::assertSame('acme/decider-2', $settings->getModel());
        self::assertSame(16_000, $settings->getModelContextWindow());
        self::assertSame(RecommendationEngineKind::Scoring, $settings->getModelKind());
        self::assertSame(ScoringProtocol::SystemOne, $settings->getScoringProtocol());
    }

    public function testChoosingAnLlmAfterAScoringModelDropsTheProtocol(): void
    {
        $settings = $this->settings();
        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );

        $settings->chooseModel(new ModelDescriptor('gpt-4o', 128_000), new \DateTimeImmutable('2026-10-05 09:05:00'));

        self::assertSame(RecommendationEngineKind::Llm, $settings->getModelKind());
        self::assertNull($settings->getScoringProtocol());
    }

    public function testANewEndpointForgetsTheModelsKindAndProtocol(): void
    {
        $settings = $this->settings();
        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-05 09:00:00'),
        );

        $settings->replaceConnection(
            'https://other.example.test/v1',
            $this->sealed('b3RoZXI='),
            'wxyz',
            new \DateTimeImmutable('2026-10-05 10:00:00'),
        );

        self::assertNull($settings->getModelKind());
        self::assertNull($settings->getScoringProtocol());
    }
}
