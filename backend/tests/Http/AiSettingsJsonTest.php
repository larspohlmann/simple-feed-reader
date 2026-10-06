<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Enum\ScoringProtocol;
use App\Http\AiSettingsJson;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\RecommendationCapabilitiesJsons;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class AiSettingsJsonTest extends TestCase
{
    use AssignsEntityIds;

    private function json(): AiSettingsJson
    {
        return new AiSettingsJson(
            RecommendationCapabilitiesJsons::ofTheKind(),
            new RecommendationEngineResolver(new ServiceLocator([])),
        );
    }

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
            $settings->chooseModel(new ModelDescriptor($model, null), new \DateTimeImmutable('2026-08-06 10:00:00'));
        }

        return $settings;
    }

    public function testConfigurationCarriesTheRowsOwnShape(): void
    {
        $settings = self::withId($this->settings('gpt-4o', 'Work OpenAI'), 1);

        $shape = $this->json()->configuration($settings, null);

        self::assertSame(1, $shape['id']);
        self::assertSame('Work OpenAI', $shape['name']);
        self::assertSame('https://api.example.test/v1', $shape['baseUrl']);
        self::assertSame('abcd', $shape['apiKeyHint']);
        self::assertSame('gpt-4o', $shape['model']);
        self::assertTrue($shape['ready']);
    }

    public function testTheConfigurationShapeCarriesItsKindsCapabilities(): void
    {
        $shape = $this->json()->configuration($this->settings('gpt-4o'), null);

        self::assertSame(RecommendationCapabilitiesJsons::LLM, $shape['capabilities']);
    }

    public function testAConfigurationNamesTheKindAndFamilyOfItsModel(): void
    {
        $settings = $this->settings(null);
        $settings->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-08-06 10:00:00'),
        );

        $shape = $this->json()->configuration($settings, null);

        self::assertSame('scoring', $shape['kind']);
        self::assertSame('decision', $shape['family']);
    }

    /** No model yet: the default kind, as its capabilities already say, and no family. */
    public function testAConfigurationWithoutAModelIsAnLlmWithoutAFamily(): void
    {
        $shape = $this->json()->configuration($this->settings(null), null);

        self::assertSame('llm', $shape['kind']);
        self::assertNull($shape['family']);
    }

    public function testConfigurationIsActiveWhenItsIdMatchesTheActiveId(): void
    {
        $settings = self::withId($this->settings(null), 7);

        $shape = $this->json()->configuration($settings, 7);

        self::assertTrue($shape['active']);
    }

    public function testConfigurationIsNotActiveWhenTheActiveIdDiffers(): void
    {
        $settings = self::withId($this->settings(null), 7);

        $shape = $this->json()->configuration($settings, 42);

        self::assertFalse($shape['active']);
    }

    public function testConfigurationIsNotActiveWhenNothingIsActive(): void
    {
        $settings = self::withId($this->settings(null), 7);

        $shape = $this->json()->configuration($settings, null);

        self::assertFalse($shape['active']);
    }

    public function testTheConfigurationShapeCarriesTheReasoningPreference(): void
    {
        $settings = $this->settings('gpt-4o');
        $settings->setSuppressReasoning(false);

        $shape = $this->json()->configuration($settings, null);

        self::assertFalse($shape['suppressReasoning']);
    }

    public function testTheConfigurationShapeSaysWhenTheModelRefusedSuppressedReasoning(): void
    {
        $settings = $this->settings('gpt-4o');

        self::assertFalse($this->json()->configuration($settings, null)['suppressionRefused']);

        $settings->recordSuppressionRefused();

        self::assertTrue($this->json()->configuration($settings, null)['suppressionRefused']);
    }

    public function testTheConfigurationShapeCarriesTheBatchConcurrency(): void
    {
        $settings = $this->settings('gpt-4o');
        $settings->setBatchConcurrency(3);

        $shape = $this->json()->configuration($settings, null);

        self::assertSame(3, $shape['batchConcurrency']);
    }

    public function testConfigurationNeverCarriesKeyMaterial(): void
    {
        $encoded = json_encode($this->json()->configuration($this->settings('gpt-4o'), null));

        self::assertIsString($encoded);
        self::assertStringNotContainsString('Y2lwaGVy', $encoded);
        self::assertStringNotContainsString('c2FsdA==', $encoded);
    }

    public function testListShapesEveryConfigurationAndCarriesTheActiveId(): void
    {
        // `active` compares ids, so the two rows need different ids.
        $first = self::withId($this->settings('gpt-4o', 'First'), 1);
        $second = self::withId($this->settings(null, 'Second'), 2);

        $shape = $this->json()->list([$first, $second], 1);

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
        $shape = $this->json()->list([self::withId($this->settings(null), 1)], null);

        self::assertNull($shape['activeId']);
        self::assertIsArray($shape['configs']);
        self::assertIsArray($shape['configs'][0]);
        self::assertFalse($shape['configs'][0]['active']);
    }

    public function testAddedCarriesTheOfferedModelsAlongsideTheConfiguration(): void
    {
        $shape = $this->json()->added(
            $this->settings(null, 'Work OpenAI'),
            [new ModelDescriptor('gpt-4o', null), new ModelDescriptor('gpt-4o-mini', null)],
        );

        self::assertSame(
            [
                [
                    'id' => 'gpt-4o',
                    'label' => null,
                    'kind' => 'llm',
                    'family' => null,
                    'capabilities' => RecommendationCapabilitiesJsons::LLM,
                ],
                [
                    'id' => 'gpt-4o-mini',
                    'label' => null,
                    'kind' => 'llm',
                    'family' => null,
                    'capabilities' => RecommendationCapabilitiesJsons::LLM,
                ],
            ],
            $shape['models'],
        );
        self::assertSame('Work OpenAI', $shape['name']);
        self::assertFalse($shape['ready']);
    }

    /** The catalog's tag decides, never the id: `jev-router` is an LLM, `acme/decider-2` a decision model. */
    public function testEachOfferedModelCarriesTheLabelKindFamilyAndCapabilitiesOfItsTag(): void
    {
        self::assertSame(
            [
                'models' => [
                    [
                        'id' => 'jev-router',
                        'label' => null,
                        'kind' => 'llm',
                        'family' => null,
                        'capabilities' => RecommendationCapabilitiesJsons::LLM,
                    ],
                    [
                        'id' => 'acme/decider-2',
                        'label' => 'System One',
                        'kind' => 'scoring',
                        'family' => 'decision',
                        'capabilities' => RecommendationCapabilitiesJsons::SCORING,
                    ],
                ],
            ],
            $this->json()->models([
                new ModelDescriptor('jev-router', 128_000),
                new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            ]),
        );
    }

    public function testConfigurationForIsActiveWhenItIsTheOwnersActiveConfiguration(): void
    {
        $settings = self::withId($this->settings(null), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $owner->setActiveAiProviderSettings($settings);

        self::assertTrue($this->json()->configurationFor($settings, $owner)['active']);
    }

    public function testConfigurationForIsNotActiveWhenTheOwnerHasAnotherOneActive(): void
    {
        $settings = self::withId($this->settings(null), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $owner->setActiveAiProviderSettings(self::withId($this->settings(null), 42));

        self::assertFalse($this->json()->configurationFor($settings, $owner)['active']);
    }

    public function testConfigurationForIsNotActiveWhenTheOwnerHasNoneActive(): void
    {
        $settings = self::withId($this->settings('gpt-4o', 'Work OpenAI'), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));

        self::assertSame(
            $this->json()->configuration($settings, null),
            $this->json()->configurationFor($settings, $owner),
        );
    }

    public function testAConfigurationCarriesNoProfileConnectionPointer(): void
    {
        $shape = $this->json()->configuration(self::withId($this->settings('gpt-4o'), 7), null);

        self::assertArrayNotHasKey('profileConnectionId', $shape);
    }
}
