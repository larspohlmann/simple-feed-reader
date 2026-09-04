<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Recommendation\RecommendationPackingSettings;
use PHPUnit\Framework\TestCase;

final class AiSettingsJsonTest extends TestCase
{
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

    /**
     * The mapper reports `active` by comparing ids, so proving both branches
     * needs two rows with genuinely different ids — something an unpersisted
     * entity never has (getId() is null until Doctrine assigns one). Stamping
     * it through reflection is the only way to get that here without pulling
     * the database into what is otherwise a plain unit test.
     */
    private function withId(AiProviderSettings $settings, int $id): AiProviderSettings
    {
        (new \ReflectionProperty(AiProviderSettings::class, 'id'))->setValue($settings, $id);

        return $settings;
    }

    public function testARowWithoutAModelIsNotReady(): void
    {
        self::assertFalse(AiSettingsJson::isReady($this->settings(null)));
    }

    public function testARowWithAModelIsReady(): void
    {
        self::assertTrue(AiSettingsJson::isReady($this->settings('gpt-4o')));
    }

    public function testNoRowIsNotReady(): void
    {
        self::assertFalse(AiSettingsJson::isReady(null));
    }

    public function testConfigurationCarriesTheRowsOwnShape(): void
    {
        $settings = $this->withId($this->settings('gpt-4o', 'Work OpenAI'), 1);

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
        $settings = $this->withId($this->settings(null), 7);

        $shape = AiSettingsJson::configuration($settings, 7);

        self::assertTrue($shape['active']);
    }

    public function testConfigurationIsNotActiveWhenTheActiveIdDiffers(): void
    {
        $settings = $this->withId($this->settings(null), 7);

        $shape = AiSettingsJson::configuration($settings, 42);

        self::assertFalse($shape['active']);
    }

    public function testConfigurationIsNotActiveWhenNothingIsActive(): void
    {
        $settings = $this->withId($this->settings(null), 7);

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
        $first = $this->withId($this->settings('gpt-4o', 'First'), 1);
        $second = $this->withId($this->settings(null, 'Second'), 2);

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
            RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
            $shape['defaultMaxBatchSize'],
        );
    }

    public function testListReportsANullActiveIdWhenNothingIsActive(): void
    {
        $shape = AiSettingsJson::list([$this->withId($this->settings(null), 1)], null);

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
}
