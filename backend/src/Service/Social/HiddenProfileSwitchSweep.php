<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Enum\SocialNetwork;
use App\Repository\RefusedProfileSubscriptionRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileImageBudgetModel;
use App\Service\Social\Model\RefusedProfileSubscriptionModel;
use App\Service\Social\Support\BlueskyActor;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Psr\Log\LoggerInterface;

/**
 * Moves the subscribers of Bluesky profile feeds refused with a 403 to author feeds when the profile hides itself from
 * logged-out readers. Its queue is the refused feeds themselves; a subscriber without an account keeps the feed.
 */
final readonly class HiddenProfileSwitchSweep
{
    private const int CANDIDATES_PER_SWEEP = 200;

    public function __construct(
        private RefusedProfileSubscriptionRepository $refusedSubscriptions,
        private HiddenProfilesInterface $hiddenProfiles,
        private PublicProfileCache $publicProfiles,
        private HiddenProfileMove $move,
        private AppViewClient $appView,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(ProfileImageBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        if ($this->appView->isThrottled()) {
            return HiddenProfileSwitchReportModel::none();
        }
        $candidates = $this->unsettledCandidates();
        $asked = self::askedActorsOf($candidates, $budget);
        if ([] === $asked) {
            return HiddenProfileSwitchReportModel::none();
        }
        try {
            $hidden = $this->hiddenProfiles->hiddenAmong($asked);
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Hidden-profile switch stopped: Bluesky did not answer.', [
                'exception' => $exception,
            ]);

            return HiddenProfileSwitchReportModel::none();
        }
        $this->publicProfiles->rememberPublic(array_values(array_diff($asked, array_keys($hidden))));

        return $this->moveHidden($candidates, $hidden);
    }

    /** @return list<RefusedProfileSubscriptionModel> the refused subscriptions whose actor is not known public */
    private function unsettledCandidates(): array
    {
        $candidates = [];
        $refused = $this->refusedSubscriptions->findOnForbiddenBlueskyProfileFeeds(self::CANDIDATES_PER_SWEEP);
        foreach ($refused as $subscription) {
            $actor = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $subscription->getFeed()->getUrl());
            if (null !== $actor && BlueskyActor::isValid($actor) && !$this->publicProfiles->isPublic($actor)) {
                $candidates[] = new RefusedProfileSubscriptionModel($subscription, $actor);
            }
        }

        return $candidates;
    }

    /**
     * @param list<RefusedProfileSubscriptionModel> $candidates
     *
     * @return list<string>
     */
    private static function askedActorsOf(array $candidates, ProfileImageBudgetModel $budget): array
    {
        $actors = array_map(
            static fn (RefusedProfileSubscriptionModel $candidate): string => $candidate->actor,
            $candidates,
        );

        return \array_slice(array_values(array_unique($actors)), 0, $budget->calls * AppViewClient::URIS_PER_CALL);
    }

    /**
     * @param list<RefusedProfileSubscriptionModel> $candidates
     * @param array<string, HiddenProfileModel>     $hidden
     */
    private function moveHidden(array $candidates, array $hidden): HiddenProfileSwitchReportModel
    {
        $report = HiddenProfileSwitchReportModel::none();
        foreach ($candidates as $candidate) {
            $profile = $hidden[$candidate->actor] ?? null;
            if (null !== $profile) {
                $report = $report->counting($this->moveOne($candidate, $profile));
            }
        }

        return $report;
    }

    private function moveOne(
        RefusedProfileSubscriptionModel $candidate,
        HiddenProfileModel $profile,
    ): HiddenProfileMoveOutcome {
        try {
            return $this->move->move($candidate->subscription, $profile);
        } catch (SubscriptionLimitReachedException $exception) {
            $this->logger->info('Hidden-profile switch skipped a subscriber at the subscription cap.', [
                'subscriptionId' => $candidate->subscription->requireId(),
                'exception' => $exception,
            ]);

            return HiddenProfileMoveOutcome::Skipped;
        }
    }
}
