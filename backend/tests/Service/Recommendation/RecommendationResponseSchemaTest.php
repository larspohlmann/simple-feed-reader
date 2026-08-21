<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationResponseSchemaTest extends TestCase
{
    /**
     * The ranking schema is the machine form of OUTPUT_CONTRACT: one object
     * with a `recommendations` array of {id, score, reason}. LM Studio rejects
     * `json_object` and requires a `json_schema`, so this shape is what makes a
     * batch call succeed there (#329).
     */
    public function testTheRankingSchemaMatchesTheBatchOutputContract(): void
    {
        $schema = RecommendationResponseSchema::Ranking->toJsonSchema();

        self::assertSame('recommendations', $schema->name);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'score' => ['type' => 'integer'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'score', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['recommendations'],
            'additionalProperties' => false,
        ], $schema->schema);
    }

    /**
     * The dedup schema is the machine form of DEDUP_OUTPUT_CONTRACT: one object
     * with a `duplicates` array of ids.
     */
    public function testTheDuplicatesSchemaMatchesTheDedupOutputContract(): void
    {
        $schema = RecommendationResponseSchema::Duplicates->toJsonSchema();

        self::assertSame('duplicates', $schema->name);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'duplicates' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['duplicates'],
            'additionalProperties' => false,
        ], $schema->schema);
    }

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
}
