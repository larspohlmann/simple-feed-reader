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
}
