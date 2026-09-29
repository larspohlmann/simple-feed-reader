<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt\Model;

use App\Service\Ai\Completion\Model\JsonSchemaModel;

/**
 * Each provider phase's structured-output schema, the machine form of RecommendationPromptText's contract. No score
 * range or id check: OpenAI's strict mode rejects `minimum`/`maximum`, so the parsers clamp scores and drop bad ids.
 */
enum RecommendationResponseSchema
{
    case Distillation;
    case BatchScore;
    case Consolidation;

    /** @var array<string, mixed> */
    private const array DISTILLATION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'profile' => ['type' => 'string'],
        ],
        'required' => ['profile'],
        'additionalProperties' => false,
    ];

    /** @var array<string, mixed> */
    private const array BATCH_SCORE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'recommendations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'score' => ['type' => 'integer'],
                    ],
                    'required' => ['id', 'score'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['recommendations'],
        'additionalProperties' => false,
    ];

    /** @var array<string, mixed> */
    private const array CONSOLIDATION_SCHEMA = [
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
            'duplicates' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
        ],
        'required' => ['recommendations', 'duplicates'],
        'additionalProperties' => false,
    ];

    public function toJsonSchema(): JsonSchemaModel
    {
        return match ($this) {
            self::Distillation => new JsonSchemaModel('profile', self::DISTILLATION_SCHEMA),
            self::BatchScore => new JsonSchemaModel('recommendations', self::BATCH_SCORE_SCHEMA),
            self::Consolidation => new JsonSchemaModel('recommendations', self::CONSOLIDATION_SCHEMA),
        };
    }
}
