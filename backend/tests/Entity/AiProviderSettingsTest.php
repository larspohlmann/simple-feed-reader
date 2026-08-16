<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

final class AiProviderSettingsTest extends TestCase
{
    private function sealed(string $ciphertext = 'Y2lwaGVy'): SealedApiKey
    {
        return new SealedApiKey($ciphertext, 'bm9uY2U=', 'c2FsdA==', 1);
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

        $settings->chooseModel('gpt-4o-mini', $verifiedAt, 128000);

        self::assertTrue($settings->hasModel());
        self::assertSame('gpt-4o-mini', $settings->getModel());
        self::assertEquals($verifiedAt, $settings->getVerifiedAt());
    }

    public function testChoosingAModelRecordsTheContextWindowTheProviderReported(): void
    {
        $settings = $this->settings();

        $settings->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-06 10:00:00'), 128000);

        self::assertSame(128000, $settings->getModelContextWindow());
    }

    public function testAModelChosenWithoutAReportedContextWindowLeavesItNull(): void
    {
        $settings = $this->settings();

        $settings->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-06 10:00:00'), null);

        self::assertNull($settings->getModelContextWindow());
    }

    public function testReplacingTheConnectionDropsTheChosenModel(): void
    {
        $settings = $this->settings();
        $settings->chooseModel('gpt-4o-mini', new \DateTimeImmutable('2026-08-06 10:00:00'), 128000);

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
        self::assertSame('b3RoZXI=', $settings->getSealedApiKey()->ciphertext);
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
        self::assertSame(1, $settings->batchConcurrency());
    }

    public function testSetBatchConcurrencyIsReadBack(): void
    {
        $settings = $this->settings();
        $settings->setBatchConcurrency(3);
        self::assertSame(3, $settings->batchConcurrency());
    }

    public function testMaxBatchConcurrencyIsFour(): void
    {
        self::assertSame(4, AiProviderSettings::MAX_BATCH_CONCURRENCY);
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

        self::assertSame(3, $settings->batchConcurrency());
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
        self::assertNull($this->settings()->maxBatchSize());
    }

    public function testSetMaxBatchSizeIsReadBack(): void
    {
        $settings = $this->settings();
        $settings->setMaxBatchSize(25);
        self::assertSame(25, $settings->maxBatchSize());
    }

    public function testSetMaxBatchSizeBackToNullRestoresTheDefault(): void
    {
        $settings = $this->settings();
        $settings->setMaxBatchSize(25);

        $settings->setMaxBatchSize(null);

        self::assertNull($settings->maxBatchSize());
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

        self::assertSame(25, $settings->maxBatchSize());
    }

    public function testMinimumBatchSizeIsFive(): void
    {
        self::assertSame(5, AiProviderSettings::MINIMUM_BATCH_SIZE);
    }

    public function testMaximumBatchSizeIsTwoHundred(): void
    {
        self::assertSame(200, AiProviderSettings::MAXIMUM_BATCH_SIZE);
    }
}
