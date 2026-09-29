<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class AiSettingsJsonTest extends TestCase
{
    use AssignsEntityIds;

    private function settings(?string $model, ?string $name = null): AiProviderSettings
    {
        $settings = new AiProviderSettings(
            new User('mapper@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            $name,
            'https://api.example.test/v1',
            new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'abcd',
            new \DateTimeImmutable('2026-08-06 09:30:00'),
        );

        if (null !== $model) {
            $settings->chooseModel($model, new \DateTimeImmutable('2026-08-06 10:00:00'), null);
        }

        return $settings;
    }

    public function testConfigurationCarriesTheRowsOwnShape(): void
    {
        $settings = self::withId($this->settings('gpt-4o', 'Work OpenAI'), 1);

        $shape = AiSettingsJson::configuration($settings, null);

        self::assertSame(1, $shape['id']);
        self::assertSame('Work OpenAI', $shape['name']);
        self::assertSame('https://api.example.test/v1', $shape['baseUrl']);
        self::assertSame('abcd', $shape['apiKeyHint']);
        self::assertSame('gpt-4o', $shape['model']);
        self::assertTrue($shape['ready']);
    }

    public function testConfigurationIsActiveWhenItsIdMatchesTheActiveId(): void
    {
        $settings = self::withId($this->settings(null), 7);

        $shape = AiSettingsJson::configuration($settings, 7);

        self::assertTrue($shape['active']);
    }

    public function testConfigurationIsNotActiveWhenTheActiveIdDiffers(): void
    {
        $settings = self::withId($this->settings(null), 7);

        $shape = AiSettingsJson::configuration($settings, 42);

        self::assertFalse($shape['active']);
    }

    public function testConfigurationIsNotActiveWhenNothingIsActive(): void
    {
        $settings = self::withId($this->settings(null), 7);

        $shape = AiSettingsJson::configuration($settings, null);

        self::assertFalse($shape['active']);
    }

    public function testTheConfigurationShapeCarriesTheReasoningPreference(): void
    {
        $settings = $this->settings('gpt-4o');
        $settings->setSuppressReasoning(false);

        $shape = AiSettingsJson::configuration($settings, null);

        self::assertFalse($shape['suppressReasoning']);
    }

    public function testTheConfigurationShapeCarriesTheBatchConcurrency(): void
    {
        $settings = $this->settings('gpt-4o');
        $settings->setBatchConcurrency(3);

        $shape = AiSettingsJson::configuration($settings, null);

        self::assertSame(3, $shape['batchConcurrency']);
    }

    public function testConfigurationNeverCarriesKeyMaterial(): void
    {
        $encoded = json_encode(AiSettingsJson::configuration($this->settings('gpt-4o'), null));

        self::assertIsString($encoded);
        self::assertStringNotContainsString('Y2lwaGVy', $encoded);
        self::assertStringNotContainsString('c2FsdA==', $encoded);
    }

    public function testListShapesEveryConfigurationAndCarriesTheActiveId(): void
    {
        // `active` compares ids, so the two rows need different ids.
        $first = self::withId($this->settings('gpt-4o', 'First'), 1);
        $second = self::withId($this->settings(null, 'Second'), 2);

        $shape = AiSettingsJson::list([$first, $second], 1);

        self::assertIsArray($shape['configs']);
        self::assertCount(2, $shape['configs']);
        self::assertIsArray($shape['configs'][0]);
        self::assertSame('First', $shape['configs'][0]['name']);
        self::assertTrue($shape['configs'][0]['active']);
        self::assertIsArray($shape['configs'][1]);
        self::assertSame('Second', $shape['configs'][1]['name']);
        self::assertFalse($shape['configs'][1]['active']);
        self::assertSame(1, $shape['activeId']);
        self::assertSame(
            RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            $shape['defaultMaxBatchSize'],
        );
    }

    public function testListReportsANullActiveIdWhenNothingIsActive(): void
    {
        $shape = AiSettingsJson::list([self::withId($this->settings(null), 1)], null);

        self::assertNull($shape['activeId']);
        self::assertIsArray($shape['configs']);
        self::assertIsArray($shape['configs'][0]);
        self::assertFalse($shape['configs'][0]['active']);
    }

    public function testAddedCarriesTheOfferedModelsAlongsideTheConfiguration(): void
    {
        $shape = AiSettingsJson::added($this->settings(null, 'Work OpenAI'), ['gpt-4o', 'gpt-4o-mini']);

        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $shape['models']);
        self::assertSame('Work OpenAI', $shape['name']);
        self::assertFalse($shape['ready']);
    }

    public function testConfigurationForIsActiveWhenItIsTheOwnersActiveConfiguration(): void
    {
        $settings = self::withId($this->settings(null), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $owner->setActiveAiProviderSettings($settings);

        self::assertTrue(AiSettingsJson::configurationFor($settings, $owner)['active']);
    }

    public function testConfigurationForIsNotActiveWhenTheOwnerHasAnotherOneActive(): void
    {
        $settings = self::withId($this->settings(null), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $owner->setActiveAiProviderSettings(self::withId($this->settings(null), 42));

        self::assertFalse(AiSettingsJson::configurationFor($settings, $owner)['active']);
    }

    public function testConfigurationForIsNotActiveWhenTheOwnerHasNoneActive(): void
    {
        $settings = self::withId($this->settings('gpt-4o', 'Work OpenAI'), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));

        self::assertSame(
            AiSettingsJson::configuration($settings, null),
            AiSettingsJson::configurationFor($settings, $owner),
        );
    }
}
