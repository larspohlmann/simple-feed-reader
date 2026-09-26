<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiReadiness;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class AiReadinessTest extends TestCase
{
    public function testARowWithoutAModelIsNotReady(): void
    {
        self::assertFalse(AiReadiness::of($this->settings(null)));
    }

    public function testARowWithAModelIsReady(): void
    {
        self::assertTrue(AiReadiness::of($this->settings('gpt-4o')));
    }

    public function testNoRowIsNotReady(): void
    {
        self::assertFalse(AiReadiness::of(null));
    }

    private function settings(?string $model): AiProviderSettings
    {
        $settings = AiProviderSettingsFactory::build(
            new User('readiness@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
        );

        if (null !== $model) {
            $settings->chooseModel($model, new \DateTimeImmutable('2026-08-06 10:00:00'), null);
        }

        return $settings;
    }
}
