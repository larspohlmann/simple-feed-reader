<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

use App\Service\Ai\Model\ProviderCallReceiptModel;
use App\Service\Ai\Model\ProviderCallUsageModel;
use App\Service\Ai\Support\ReplyField;
use App\Service\Ai\Support\ReportedCost;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;

/**
 * Never throws: a body that is not the documented shape decodes to no scores, which the parser rejects as unusable.
 * The request id is TypeSafe's header when sent, else the body's `id` (OpenRouter's generation id).
 */
final class SystemOneReplyDecoder
{
    public static function decode(string $body, ?string $requestIdHeader): ScoringReplyModel
    {
        $root = json_decode($body, true);
        $root = \is_array($root) ? $root : [];

        return new ScoringReplyModel(
            $body,
            self::scoresIn($root['answers'] ?? null),
            new ProviderCallReceiptModel(
                ReplyField::text($requestIdHeader) ?? ReplyField::text($root['id'] ?? null),
                ReplyField::text($root['model'] ?? null),
                self::usageIn($root['usage'] ?? null),
            ),
        );
    }

    /** @return array<int, float> each answered question's Noul, by the entry its id names */
    private static function scoresIn(mixed $answers): array
    {
        if (!\is_array($answers)) {
            return [];
        }

        $scores = [];
        foreach ($answers as $questionId => $answer) {
            $entryId = \is_string($questionId) ? QuestionId::entryIdOf($questionId) : null;
            $noul = \is_array($answer) ? ($answer['noul'] ?? null) : null;
            if (null !== $entryId && (\is_float($noul) || \is_int($noul))) {
                $scores[$entryId] = (float) $noul;
            }
        }

        return $scores;
    }

    /** TypeSafe documents `input_tokens`/`output_tokens`; an OpenAI-style gateway may say prompt/completion. */
    private static function usageIn(mixed $usage): ?ProviderCallUsageModel
    {
        if (!\is_array($usage)) {
            return null;
        }

        return new ProviderCallUsageModel(
            promptTokens: self::countIn($usage, 'input_tokens', 'prompt_tokens'),
            completionTokens: self::countIn($usage, 'output_tokens', 'completion_tokens'),
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: ReportedCost::nanoCreditsOf($usage['cost'] ?? null),
        );
    }

    /** @param array<mixed> $usage */
    private static function countIn(array $usage, string $documentedKey, string $gatewayKey): int
    {
        return ReplyField::count($usage[$documentedKey] ?? $usage[$gatewayKey] ?? null);
    }

    private function __construct()
    {
    }
}
