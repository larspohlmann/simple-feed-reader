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
    case Ranking;
    case Duplicates;

    /** @var array<string, mixed> */
    private const array RANKING_SCHEMA = [
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
    ];

    /** @var array<string, mixed> */
    private const array DUPLICATES_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'duplicates' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
        ],
        'required' => ['duplicates'],
        'additionalProperties' => false,
    ];

    public function toJsonSchema(): JsonSchema
    {
        return match ($this) {
            self::Ranking => new JsonSchema('recommendations', self::RANKING_SCHEMA),
            self::Duplicates => new JsonSchema('duplicates', self::DUPLICATES_SCHEMA),
        };
    }
}
