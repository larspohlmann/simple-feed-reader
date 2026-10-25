<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\DayWindowModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\ORM\EntityManagerInterface;

/** Must run before the pruner in the same pass: a day is recorded while every entry it counts still exists. */
final readonly class SubscriptionHistoryRecorder
{
    public const int WINDOW_DAYS = 30;

    public function __construct(
        private SubscriptionActivityRepository $activity,
        private SubscriptionDayRepository $storedDays,
        private LiveDayCounter $liveCounter,
        private RetentionHorizon $horizon,
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function recordCompletedDays(): int
    {
        $window = DayWindowModel::endingOn(UtcDay::of($this->clock->now()), self::WINDOW_DAYS);
        $subscriptions = $this->activity->trackedSubscriptions();
        $sources = new DaySources(
            $this->storedDays->since(TrackedSubscriptionModel::idsOf($subscriptions), $window->oldest()),
            $this->horizon->asOf($window->today()),
        );
        $oldestLiveDay = $sources->oldestLiveDay($subscriptions, $window->completedDates());
        if ($oldestLiveDay === null) {
            return 0;
        }

        $live = $this->liveCounter->since($subscriptions, $oldestLiveDay);
        $written = 0;
        foreach ($subscriptions as $subscription) {
            $written += $this->recordSubscription($subscription, $window, $sources, $live);
        }
        $this->entityManager->flush();

        return $written;
    }

    private function recordSubscription(
        TrackedSubscriptionModel $subscription,
        DayWindowModel $window,
        DaySources $sources,
        DayCountsModel $live,
    ): int {
        $reference = $this->entityManager->getReference(Subscription::class, $subscription->subscriptionId)
            ?? throw new \LogicException('A tracked subscription has no reference.');
        $written = 0;
        foreach ($window->completedDates() as $day) {
            if (!$sources->needsLive($subscription, $day)) {
                continue;
            }
            $counts = $live->of($subscription->subscriptionId, $day);
            $this->entityManager->persist(new SubscriptionDay(
                $reference,
                UtcDay::start($day),
                $counts->arrived,
                $counts->read,
            ));
            ++$written;
        }

        return $written;
    }
}
