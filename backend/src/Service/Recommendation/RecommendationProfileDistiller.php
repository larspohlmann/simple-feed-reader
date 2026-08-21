<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Ai\ProviderConnectionFactory;

/**
 * The distillation phase's single provider call (#493). Once a run starts,
 * before any batch is scored, one call over the reader's full weighted
 * history -- favorites, kept, viewed -- produces a short preference profile
 * that every later phase reads instead of that history. This class mirrors
 * RecommendationConsolidationResolver: it loads what the call needs, calls
 * the provider, parses the reply and settles the debug row it opened, then
 * hands back a ProfileDistillationOutcome for the advancer's distillTick to
 * write. It never touches the run's persisted progress and never retries on
 * its own.
 *
 * A usable reply is cached on RecommendationSettings here, not only handed
 * back: storeProfile() is what makes the profile survive past this run, for
 * an account that goes on to skip distillation on a later one (#493). An
 * unusable reply resolves to the offending reply so the advancer can retry
 * the call next tick or degrade once attempts run out -- the profile prompt
 * has no pool to fall back to the way the consolidation phase falls back to
 * the batch-scored pool, so a degraded run simply proceeds without one. A
 * transport failure throws, exactly as the consolidation resolver does, and
 * the advancer folds it into the run's ceiling.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class RecommendationProfileDistiller
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private ChatCompletionClient $chat,
        private ProviderConnectionFactory $connections,
        private RecommendationProfileParser $profileParser,
        private RecommendationTickCheckpoint $checkpoint,
        private RecommendationSettingsWriter $settingsWriter,
    ) {
    }

    public function distill(
        RecommendationRun $run,
        AiProviderSettings $settings,
        int $userId,
        EffectiveRecommendationSettings $effectiveSettings,
    ): ProfileDistillationOutcome {
        $history = $this->historyLoader->load($userId, $effectiveSettings);

        $messages = $this->promptBuilder->messagesWithCorrectiveTail(
            $this->promptBuilder->distillMessages($history, $effectiveSettings),
            $run->getLastInvalidReply(),
            RecommendationPromptText::DISTILL_CORRECTIVE,
        );

        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DISTILL,
            null,
            $messages,
            $settings->getModel() ?? '',
        );

        $content = $this->callProvider(
            $settings,
            $this->requestFactory->create($settings, $messages, 1, RecommendationResponseSchema::Distillation),
            $recordedCall,
        );

        $result = $this->profileParser->parse($content);
        $recordedCall->settle($content, $result->usable);
        $this->checkpoint->guard($run);

        if (!$result->usable) {
            return ProfileDistillationOutcome::unusable($content);
        }

        $profile = $result->profile
            ?? throw new \LogicException('A usable profile parse result has no profile text.');
        $this->settingsWriter->storeProfile($run->getUser(), $profile);

        return ProfileDistillationOutcome::usable($profile);
    }

    /**
     * The distillation phase's single provider call, recorded for the debug
     * view from the moment the request goes out (#309), mirroring
     * RecommendationConsolidationResolver::callProvider(). Any failure settles the
     * debug row before unwinding: begin() has already persisted it, and a
     * verdict left null reads to the debug panel as "still streaming"
     * forever. The exception is always re-thrown unchanged, so the advancer
     * still tells a transport failure (which touches the run's ceiling) apart
     * from an unreadable key (which fails the run permanently) purely by its
     * type -- credentials() decrypting the stored key runs inside this same
     * try, so an unreadable key never leaves the row stuck either.
     */
    private function callProvider(
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
