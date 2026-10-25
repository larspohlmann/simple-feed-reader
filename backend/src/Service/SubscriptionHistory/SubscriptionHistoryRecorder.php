<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Repository\SubscriptionActivityRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\SubscriptionHistory\Factory\DaySourcesFactory;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\DayWindowModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\ORM\EntityManagerInterface;

/** Must run before the pruner in the same pass: a day is recorded while every entry it counts still exists. */
final readonly class SubscriptionHistoryRecorder
{
    private const int FLUSH_CHUNK_SIZE = 500;

    public function __construct(
        private SubscriptionActivityRepository $activity,
        private DaySourcesFactory $daySourcesFactory,
        private LiveDayCounter $liveCounter,
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function recordCompletedDays(): int
    {
        $window = DayWindowModel::lastDays(UtcDay::of($this->clock->now()));
        $subscriptions = $this->activity->trackedSubscriptions();
        $missingDays = self::missingDaysOf(
            $subscriptions,
            $this->daySourcesFactory->forWindow($subscriptions, $window),
            $window->completedDates(),
        );
        if ($missingDays === []) {
            return 0;
        }

        $pending = array_values(array_filter(
            $subscriptions,
            static fn (TrackedSubscriptionModel $tracked): bool => isset($missingDays[$tracked->subscriptionId]),
        ));
        $oldestMissingDay = min(array_map(static fn (array $days): string => $days[0], $missingDays));
        $live = $this->liveCounter->since($pending, $oldestMissingDay);

        return $this->saveInChunks($this->newDays($pending, $missingDays, $live));
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     * @param list<string>                   $dates
     *
     * @return array<int, non-empty-list<string>> subscription id => the days only a live count can still fill
     */
    private static function missingDaysOf(array $subscriptions, DaySources $sources, array $dates): array
    {
        $missingDays = [];
        foreach ($subscriptions as $subscription) {
            $days = $sources->liveDays($subscription, $dates);
            if ($days !== []) {
                $missingDays[$subscription->subscriptionId] = $days;
            }
        }

        return $missingDays;
    }

    /**
     * @param list<TrackedSubscriptionModel>     $pending
     * @param array<int, non-empty-list<string>> $missingDays
     *
     * @return \Generator<SubscriptionDay>
     */
    private function newDays(array $pending, array $missingDays, DayCountsModel $live): \Generator
    {
        foreach ($pending as $subscription) {
            $reference = $this->entityManager->getReference(Subscription::class, $subscription->subscriptionId)
                ?? throw new \LogicException('A tracked subscription has no reference.');
            foreach ($missingDays[$subscription->subscriptionId] as $day) {
                $counts = $live->of($subscription->subscriptionId, $day);
                yield new SubscriptionDay($reference, UtcDay::start($day), $counts->arrived, $counts->read);
            }
        }
    }

    /**
     * @param iterable<SubscriptionDay> $days
     */
    private function saveInChunks(iterable $days): int
    {
        $written = 0;
        foreach (self::chunksOf($days) as $chunk) {
            foreach ($chunk as $day) {
                $this->entityManager->persist($day);
            }
            $this->entityManager->flush();
            foreach ($chunk as $day) {
                $this->entityManager->detach($day);
            }
            $written += \count($chunk);
        }

        return $written;
    }

    /**
     * @param iterable<SubscriptionDay> $days
     *
     * @return \Generator<list<SubscriptionDay>>
     */
    private static function chunksOf(iterable $days): \Generator
    {
        $chunk = [];
        foreach ($days as $day) {
            $chunk[] = $day;
            if (\count($chunk) === self::FLUSH_CHUNK_SIZE) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }
}
