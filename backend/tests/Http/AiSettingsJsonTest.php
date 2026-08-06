<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

final class AiSettingsJsonTest extends TestCase
{
    private function settings(?string $model): AiProviderSettings
    {
        $settings = new AiProviderSettings(
            new User('mapper@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            'https://api.example.test/v1',
            new SealedApiKey('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'abcd',
            new \DateTimeImmutable('2026-08-06 09:30:00'),
        );

        if (null !== $model) {
            $settings->chooseModel($model, new \DateTimeImmutable('2026-08-06 10:00:00'));
        }

        return $settings;
    }

    public function testNoRowIsNeitherConfiguredNorReady(): void
    {
        $state = AiSettingsJson::state(null);

        self::assertFalse($state['configured']);
        self::assertFalse($state['ready']);
        self::assertNull($state['baseUrl']);
        self::assertNull($state['apiKeyHint']);
    }

    public function testAProviderWithoutAModelIsConfiguredButNotReady(): void
    {
        $state = AiSettingsJson::state($this->settings(null));

        self::assertTrue($state['configured']);
        self::assertFalse($state['ready']);
        self::assertSame('abcd', $state['apiKeyHint']);
    }

    public function testAProviderWithAModelIsReady(): void
    {
        $state = AiSettingsJson::state($this->settings('gpt-4o'));

        self::assertTrue($state['ready']);
        self::assertSame('gpt-4o', $state['model']);
    }

    public function testTheStateNeverCarriesKeyMaterial(): void
    {
        $encoded = json_encode(AiSettingsJson::state($this->settings('gpt-4o')));

        self::assertIsString($encoded);
        self::assertStringNotContainsString('Y2lwaGVy', $encoded);
        self::assertStringNotContainsString('c2FsdA==', $encoded);
    }

    public function testTheModelListRidesAlongsideTheState(): void
    {
        $state = AiSettingsJson::stateWithModels($this->settings(null), ['gpt-4o', 'gpt-4o-mini']);

        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $state['models']);
        self::assertTrue($state['configured']);
    }
}
