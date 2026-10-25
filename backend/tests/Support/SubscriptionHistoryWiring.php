<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SubscriptionDay;
use App\Repository\SubscriptionActivityRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Retention\RetentionSettings;
use App\Service\SubscriptionHistory\Factory\DaySourcesFactory;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\RetentionHorizon;
use App\Service\SubscriptionHistory\SubscriptionActivityCounter;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** The history recorder and counter over the EntityManager's real repositories. */
final readonly class SubscriptionHistoryWiring
{
    private SubscriptionActivityRepository $activity;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetentionSettings $settings,
        private ClockInterface $clock,
    ) {
        $this->activity = new SubscriptionActivityRepository($entityManager);
    }

    public function recorder(): SubscriptionHistoryRecorder
    {
        return new SubscriptionHistoryRecorder(
            $this->activity,
            $this->daySourcesFactory(),
            new LiveDayCounter($this->activity),
            new NaiveUtcClock($this->clock),
            $this->entityManager,
        );
    }

    public function counter(): SubscriptionActivityCounter
    {
        return new SubscriptionActivityCounter(
            $this->activity,
            $this->daySourcesFactory(),
            new LiveDayCounter($this->activity),
            new NaiveUtcClock($this->clock),
        );
    }

    private function daySourcesFactory(): DaySourcesFactory
    {
        return new DaySourcesFactory(
            $this->entityManager->getRepository(SubscriptionDay::class),
            new RetentionHorizon($this->settings),
        );
    }
}
