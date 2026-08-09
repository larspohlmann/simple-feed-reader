<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The structured-output schema each provider phase asks for, the machine form
 * of the prose in RecommendationPromptText. Sent as a `json_schema` response
 * format because current LM Studio rejects the older `json_object` with a 400,
 * and a strict schema also constrains a weak local model to valid JSON (#329).
 *
 * The score range and id validity are not expressed here: they belong to the
 * strict subset OpenAI structured outputs does not accept (`minimum`,
 * `maximum`), and RecommendationPickParser already clamps the score and drops
 * unknown ids. Keeping the schema inside that shared subset is what lets the
 * same request body succeed against both LM Studio and OpenAI-compatible hosts.
 */
enum RecommendationResponseSchema
{
    case Ranking;
    case Duplicates;

    public function toJsonSchema(): JsonSchema
    {
        return match ($this) {
            self::Ranking => new JsonSchema('recommendations', self::rankingSchema()),
            self::Duplicates => new JsonSchema('duplicates', self::duplicatesSchema()),
        };
    }

    /** @return array<string, mixed> */
    private static function rankingSchema(): array
    {
        return [
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
    }

    /** @return array<string, mixed> */
    private static function duplicatesSchema(): array
    {
        return [
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
    }
}
