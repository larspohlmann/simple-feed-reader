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
use App\Service\Social\Model\RefusedFeedPositionModel;
use App\Service\Social\Model\RefusedProfileSubscriptionModel;
use App\Service\Social\Pass\RefusedSubscriptionSelection;
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
    private const int MAX_PAGES = 3;
    private const int MAX_MOVES = 20;

    public function __construct(
        private RefusedProfileSubscriptionRepository $refusedSubscriptions,
        private HiddenProfilesInterface $hiddenProfiles,
        private ProfileVisibilityCache $profileVisibility,
        private HiddenProfileSwitchCursor $cursor,
        private HiddenProfileMove $move,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(ProfileCallBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        if ($budget->isEmpty()) {
            return HiddenProfileSwitchReportModel::none();
        }
        $selection = $this->selected(new RefusedSubscriptionSelection(
            $budget->calls * AppViewClient::URIS_PER_CALL,
            self::MAX_MOVES,
            $this->cursor->position(),
        ));

        return $this->moveHidden($selection->candidates(), $this->askedHidden($selection));
    }

    /** Reads on from the cursor, a few pages at most, and starts over from the first row after the last page. */
    private function selected(RefusedSubscriptionSelection $selection): RefusedSubscriptionSelection
    {
        for ($pagesRead = 0; $pagesRead < self::MAX_PAGES && !$selection->isFull(); ++$pagesRead) {
            $page = $this->refusedSubscriptions->findOnRefusedBlueskyProfileFeeds(
                $selection->position(),
                self::PAGE_SIZE,
            );
            if (!$this->selectAmong($page, $selection)) {
                break;
            }
            if (\count($page) < self::PAGE_SIZE) {
                $selection->restart();
                break;
            }
        }

        return $selection;
    }

    /**
     * @param list<Subscription> $page
     *
     * @return bool false once a subscription does not fit, which then waits for the next run
     */
    private function selectAmong(array $page, RefusedSubscriptionSelection $selection): bool
    {
        foreach ($page as $subscription) {
            $candidate = $this->candidateOf($subscription);
            if (null === $candidate) {
                $selection->passed($subscription);
                continue;
            }
            if (!$selection->admits($candidate)) {
                return false;
            }
            $selection->add($candidate);
        }

        return true;
    }

    /** @return RefusedProfileSubscriptionModel|null null when the feed names no actor, or one known public */
    private function candidateOf(Subscription $subscription): ?RefusedProfileSubscriptionModel
    {
        $actor = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $subscription->getFeed()->getUrl());
        if (null === $actor || !BlueskyActor::isValid($actor)) {
            return null;
        }
        $visibility = $this->profileVisibility->visibilityOf($actor);

        return $visibility->isPublic()
            ? null
            : new RefusedProfileSubscriptionModel($subscription, $actor, $visibility->hidden);
    }

    /** @return array<string, HiddenProfileModel> none when Bluesky cannot say, and the run then resumes where it began */
    private function askedHidden(RefusedSubscriptionSelection $selection): array
    {
        try {
            $hidden = $this->hiddenAmong($selection->unknownActors());
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Hidden-profile switch stopped: Bluesky did not answer.', [
                'exception' => $exception,
            ]);

            return [];
        }
        $this->keepPosition($selection->position());

        return $hidden;
    }

    /**
     * @param list<string> $actors
     *
     * @return array<string, HiddenProfileModel>
     */
    private function hiddenAmong(array $actors): array
    {
        return [] === $actors ? [] : $this->hiddenProfiles->hiddenAmong($actors);
    }

    private function keepPosition(?RefusedFeedPositionModel $position): void
    {
        if (null === $position) {
            $this->cursor->restart();

            return;
        }
        $this->cursor->resumeAfter($position);
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
        } catch (SubscriptionLimitReachedException | AlreadySubscribedException $exception) {
            $this->logger->info('Hidden-profile switch skipped a subscriber it cannot give the author feed.', [
                'subscriptionId' => $candidate->subscription->requireId(),
                'exception' => $exception,
            ]);

            return HiddenProfileMoveOutcome::Skipped;
        }
    }
}
