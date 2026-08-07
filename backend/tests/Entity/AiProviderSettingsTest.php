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

    private function settings(): AiProviderSettings
    {
        return new AiProviderSettings(
            new User('reader@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            'https://api.example.test/v1',
            $this->sealed(),
            'cdef',
            new \DateTimeImmutable('2026-08-06 09:30:00'),
        );
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
}
