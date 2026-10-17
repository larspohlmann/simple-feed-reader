<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\RefusedProfileSubscriptionRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Model\RefusedProfileSubscriptionModel;
use App\Service\Social\Support\BlueskyActor;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Psr\Log\LoggerInterface;

/**
 * Moves the subscribers of Bluesky profile feeds refused with a 403 to author feeds when the profile hides itself from
 * logged-out readers. Its queue is the refused feeds themselves; a subscriber without an account keeps the feed.
 */
final readonly class HiddenProfileSwitchSweep
{
    private const int PAGE_SIZE = 200;

    public function __construct(
        private RefusedProfileSubscriptionRepository $refusedSubscriptions,
        private HiddenProfilesInterface $hiddenProfiles,
        private ProfileVisibilityCache $profileVisibility,
        private HiddenProfileMove $move,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(ProfileCallBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        if ($budget->isEmpty()) {
            return HiddenProfileSwitchReportModel::none();
        }
        $askLimit = $budget->calls * AppViewClient::URIS_PER_CALL;
        $candidates = $this->unsettledCandidates($askLimit);

        return $this->moveHidden(
            $candidates,
            $this->askedHidden(\array_slice(self::unknownActorsOf($candidates), 0, $askLimit)),
        );
    }

    /**
     * Pages through the refused subscriptions until they name $askLimit actors of unknown visibility, so rows already
     * settled can never crowd out the ones behind them.
     *
     * @return list<RefusedProfileSubscriptionModel>
     */
    private function unsettledCandidates(int $askLimit): array
    {
        $candidates = [];
        $after = null;
        do {
            $page = $this->refusedSubscriptions->findOnRefusedBlueskyProfileFeeds($after, self::PAGE_SIZE);
            $candidates = [...$candidates, ...$this->unsettledAmong($page)];
            $after = $page[array_key_last($page)] ?? null;
        } while (self::PAGE_SIZE === \count($page) && \count(self::unknownActorsOf($candidates)) < $askLimit);

        return $candidates;
    }

    /**
     * @param list<Subscription> $page
     *
     * @return list<RefusedProfileSubscriptionModel> the subscriptions whose actor is not known public
     */
    private function unsettledAmong(array $page): array
    {
        $candidates = [];
        foreach ($page as $subscription) {
            $actor = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $subscription->getFeed()->getUrl());
            if (null === $actor || !BlueskyActor::isValid($actor)) {
                continue;
            }
            $visibility = $this->profileVisibility->visibilityOf($actor);
            if (!$visibility->isPublic()) {
                $candidates[] = new RefusedProfileSubscriptionModel($subscription, $actor, $visibility->hidden);
            }
        }

        return $candidates;
    }

    /**
     * @param list<RefusedProfileSubscriptionModel> $candidates
     *
     * @return list<string>
     */
    private static function unknownActorsOf(array $candidates): array
    {
        $unknown = array_filter(
            $candidates,
            static fn (RefusedProfileSubscriptionModel $candidate): bool => null === $candidate->knownHidden,
        );

        return array_values(array_unique(array_map(
            static fn (RefusedProfileSubscriptionModel $candidate): string => $candidate->actor,
            $unknown,
        )));
    }

    /**
     * @param list<string> $asked
     *
     * @return array<string, HiddenProfileModel> the hidden ones among $asked; none when Bluesky cannot say
     */
    private function askedHidden(array $asked): array
    {
        if ([] === $asked) {
            return [];
        }
        try {
            return $this->hiddenProfiles->hiddenAmong($asked);
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Hidden-profile switch stopped: Bluesky did not answer.', [
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * @param list<RefusedProfileSubscriptionModel> $candidates
     * @param array<string, HiddenProfileModel>     $hidden
     */
    private function moveHidden(array $candidates, array $hidden): HiddenProfileSwitchReportModel
    {
        $report = HiddenProfileSwitchReportModel::none();
        foreach ($candidates as $candidate) {
            $profile = $candidate->knownHidden ?? $hidden[$candidate->actor] ?? null;
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
            return $this->skipped(
                'Hidden-profile switch skipped a subscriber at the subscription cap.',
                $candidate,
                $exception,
            );
        } catch (AlreadySubscribedException $exception) {
            return $this->skipped(
                'Hidden-profile switch skipped a subscriber already subscribed to the author feed.',
                $candidate,
                $exception,
            );
        }
    }

    private function skipped(
        string $message,
        RefusedProfileSubscriptionModel $candidate,
        \RuntimeException $exception,
    ): HiddenProfileMoveOutcome {
        $this->logger->info($message, [
            'subscriptionId' => $candidate->subscription->requireId(),
            'exception' => $exception,
        ]);

        return HiddenProfileMoveOutcome::Skipped;
    }
}
