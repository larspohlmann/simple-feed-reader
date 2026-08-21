<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Service\Ai\ProviderConnectionFactory;

/**
 * One recorded provider call for the single-call phases -- distillation and
 * consolidation (#493). It opens the connection and runs the completion; the
 * reason it exists rather than a bare ChatCompletionClient call is the failure
 * path: it settles the debug row on any error before unwinding.
 *
 * RecommendationCallRecorder::begin() persisted that row the moment the request
 * went out (#309); a verdict left null reads to the debug panel as "still
 * streaming" forever. The exception is always re-thrown unchanged, so the
 * advancer still tells a transport failure (which touches the run's ceiling)
 * apart from an unreadable key (which fails the run permanently) purely by its
 * type -- forSettings() decrypting the stored key runs inside this same try,
 * so an unreadable key never leaves the row stuck either.
 *
 * The batch phase settles many rows over one wave and keeps its own
 * RecommendationBatchWave::completeRound(); this collaborator serves only the
 * phases that make exactly one recorded call.
 */
final readonly class RecommendationProviderCall
{
    public function __construct(
        private ChatCompletionClient $chat,
        private ProviderConnectionFactory $connections,
    ) {
    }

    public function complete(
        AiProviderSettings $settings,
        CompletionRequest $request,
        RecordedCall $recordedCall,
    ): string {
        try {
            return $this->chat->complete(
                $this->connections->forSettings($settings),
                $request,
                $recordedCall,
            );
        } catch (\Throwable $e) {
            $recordedCall->abortAfterTransportFailure($e->getMessage());

            throw $e;
        }
    }
}
