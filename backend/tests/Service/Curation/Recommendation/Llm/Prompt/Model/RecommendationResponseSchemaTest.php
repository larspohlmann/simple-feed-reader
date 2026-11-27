<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Llm\Prompt\Model;

use App\Service\Curation\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationResponseSchemaTest extends TestCase
{
    public function testBatchScoreSchemaAsksForIdAndScoreOnly(): void
    {
        $schema = RecommendationResponseSchema::BatchScore->toJsonSchema();

        /** @var array<string, mixed> $properties */
        $properties = $schema->schema['properties'];
        /** @var array<string, mixed> $recommendations */
        $recommendations = $properties['recommendations'];
        /** @var array{required: list<string>, properties: array<string, mixed>, additionalProperties: bool} $item */
        $item = $recommendations['items'];

        self::assertSame(['id', 'score'], $item['required']);
        self::assertArrayNotHasKey('reason', $item['properties']);
        self::assertFalse($item['additionalProperties']);
    }

    public function testConsolidationSchemaCarriesReasonsAndDuplicates(): void
    {
        $schema = RecommendationResponseSchema::Consolidation->toJsonSchema();

        /** @var array<string, mixed> $properties */
        $properties = $schema->schema['properties'];
        /** @var array<string, mixed> $recommendations */
        $recommendations = $properties['recommendations'];
        /** @var array{required: list<string>} $item */
        $item = $recommendations['items'];
        self::assertSame(['id', 'score', 'reason'], $item['required']);

        /** @var array<string, mixed> $duplicates */
        $duplicates = $properties['duplicates'];
        /** @var array{type: string} $duplicatesItems */
        $duplicatesItems = $duplicates['items'];
        self::assertSame('integer', $duplicatesItems['type']);

        self::assertSame(['recommendations', 'duplicates'], $schema->schema['required']);
    }

    public function testDistillationSchemaAsksForAProfileString(): void
    {
        $schema = RecommendationResponseSchema::Distillation->toJsonSchema();

        /** @var array<string, mixed> $properties */
        $properties = $schema->schema['properties'];
        /** @var array{type: string} $profile */
        $profile = $properties['profile'];

        self::assertSame('string', $profile['type']);
        self::assertSame(['profile'], $schema->schema['required']);
    }

    public function testStoryTitleSchemaAsksForATitleAndASynopsisString(): void
    {
        $schema = RecommendationResponseSchema::StoryTitle->toJsonSchema();

        self::assertSame('story_title', $schema->name);
        self::assertSame([
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'synopsis' => ['type' => 'string']],
            'required' => ['title', 'synopsis'],
            'additionalProperties' => false,
        ], $schema->schema);
    }

    public function testInterestTermsSchemaRequiresLanguageTermAndWeightPerTerm(): void
    {
        $schema = RecommendationResponseSchema::InterestTerms->toJsonSchema();

        self::assertSame('interest_terms', $schema->name);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'terms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'language' => ['type' => 'string'],
                            'term' => ['type' => 'string'],
                            'weight' => ['type' => 'integer'],
                        ],
                        'required' => ['language', 'term', 'weight'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['terms'],
            'additionalProperties' => false,
        ], $schema->schema);
    }
}
