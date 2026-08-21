<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The structured-output schema each provider phase asks for, the machine form
 * of the prose in RecommendationPromptText. OpenAiCompatibleChatClient records
 * why the answer is sent as a `json_schema` response format (#329).
 *
 * The score range and id validity are deliberately left out: they need the
 * strict-mode keywords (`minimum`, `maximum`) OpenAI structured outputs
 * rejects, and RecommendationPickParser already clamps the score and drops
 * unknown ids. Staying inside that shared subset is what lets one request body
 * succeed against both LM Studio and OpenAI-compatible hosts.
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

    public function toJsonSchema(): JsonSchema
    {
        return match ($this) {
            self::Distillation => new JsonSchema('profile', self::DISTILLATION_SCHEMA),
            self::BatchScore => new JsonSchema('recommendations', self::BATCH_SCORE_SCHEMA),
            self::Consolidation => new JsonSchema('recommendations', self::CONSOLIDATION_SCHEMA),
        };
    }
}
