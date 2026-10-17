<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Subscription;
use App\Enum\SocialNetwork;
use App\Repository\RefusedProfileSubscriptionRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\CachedHiddenProfiles;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\HiddenProfileMoveOutcome;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Model\ProfileVisibilityModel;
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
    private const string IDLE_PAUSE = '+1 hour';
    private const string UNANSWERED_PAUSE = '+5 minutes';

    public function __construct(
        private RefusedProfileSubscriptionRepository $refusedSubscriptions,
        private CachedHiddenProfiles $hiddenProfiles,
        private ProfileVisibilityCache $profileVisibility,
        private HiddenProfileSwitchCursor $cursor,
        private HiddenProfileMove $move,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(ProfileCallBudgetModel $budget): HiddenProfileSwitchReportModel
    {
        if ($this->cursor->isPaused()) {
            return HiddenProfileSwitchReportModel::none();
        }
        $selection = $this->selected(new RefusedSubscriptionSelection(
            $budget->calls * AppViewClient::URIS_PER_CALL,
            self::MAX_MOVES,
            $this->cursor->position(),
        ));
        $report = $this->moveHidden($selection->candidates(), $this->askedHidden($selection));
        if ($selection->isPassComplete() && [] === $selection->unknownActors() && $report->isIdle()) {
            $this->cursor->pauseFor(self::IDLE_PAUSE);
        }

        return $report;
    }

    /** Reads on from the cursor, a few pages at most, and starts over from the first row after the last page. */
    private function selected(RefusedSubscriptionSelection $selection): RefusedSubscriptionSelection
    {
        for ($pagesRead = 0; $pagesRead < self::MAX_PAGES && !$selection->isAskFull(); ++$pagesRead) {
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
        $actors = self::actorsOf($page);
        $visibilities = $this->profileVisibility->visibilitiesOf(array_values(array_unique($actors)));
        foreach ($page as $index => $subscription) {
            $candidate = self::candidateOf($subscription, $actors[$index] ?? null, $visibilities);
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

    /**
     * @param array<string, ProfileVisibilityModel> $visibilities
     *
     * @return RefusedProfileSubscriptionModel|null null when the feed names no actor, or one known public
     */
    private static function candidateOf(
        Subscription $subscription,
        ?string $actor,
        array $visibilities,
    ): ?RefusedProfileSubscriptionModel {
        if (null === $actor || $visibilities[$actor]->isPublic()) {
            return null;
        }

        return new RefusedProfileSubscriptionModel($subscription, $actor, $visibilities[$actor]->hidden);
    }

    /**
     * @param list<Subscription> $page
     *
     * @return array<int, string> the actor each refused feed follows, by its position in $page
     */
    private static function actorsOf(array $page): array
    {
        $actors = [];
        foreach ($page as $index => $subscription) {
            $actor = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $subscription->getFeed()->getUrl());
            if (null !== $actor && BlueskyActor::isValid($actor)) {
                $actors[$index] = $actor;
            }
        }

        return $actors;
    }

    /** @return array<string, HiddenProfileModel> none when Bluesky cannot say, and the run then resumes where it began */
    private function askedHidden(RefusedSubscriptionSelection $selection): array
    {
        try {
            $hidden = $this->hiddenProfiles->askUnknown($selection->unknownActors());
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Hidden-profile switch stopped: Bluesky did not answer.', [
                'exception' => $exception,
            ]);
            $this->cursor->pauseFor(self::UNANSWERED_PAUSE);

            return [];
        }
        $this->cursor->store($selection->position());

        return $hidden;
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
