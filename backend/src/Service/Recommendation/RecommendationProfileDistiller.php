<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;

/**
 * The distillation phase's single provider call (#493). Once a run starts, before any
 * batch is scored, one call over the reader's full weighted history -- favorites, kept,
 * viewed -- produces a short preference profile that every later phase reads instead of
 * that history. Mirrors RecommendationConsolidationResolver: it loads what the call needs,
 * calls the provider, parses the reply, settles the debug row it opened, and hands back a
 * ProfileDistillationOutcome for the advancer's distillTick to write. It never touches the
 * run's persisted progress and never retries on its own.
 *
 * A usable reply is cached on RecommendationSettings via storeProfile(), which is what
 * makes the profile survive past this run, for an account that skips distillation on a
 * later one (#493). An unusable reply resolves to the offending reply so the advancer can
 * retry next tick or degrade once attempts run out -- the profile prompt has no pool to
 * fall back to the way consolidation falls back to the batch-scored pool, so a degraded
 * run simply proceeds without one. A transport failure throws, exactly as in the
 * consolidation resolver, and the advancer folds it into the run's ceiling.
 */
final readonly class RecommendationProfileDistiller
{
    public function __construct(
        private RecommendationHistoryLoader $historyLoader,
        private RecommendationPromptBuilder $promptBuilder,
        private RecommendationCallRecorder $callRecorder,
        private RecommendationCompletionRequestFactory $requestFactory,
        private RecommendationProviderCall $providerCall,
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

        $content = $this->providerCall->complete(
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
}
