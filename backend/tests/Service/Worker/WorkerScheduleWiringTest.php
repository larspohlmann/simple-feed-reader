<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Service\Worker\Message\SendDueDigests;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use App\Service\Worker\WorkerSchedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\RecurringMessage;

/** Pins what the container wires, so a refactor cannot silently drop a schedule entry or change its cadence. */
final class WorkerScheduleWiringTest extends KernelTestCase
{
    public function testTheWorkerScheduleCarriesExactlyTheDecidedEntries(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(WorkerSchedule::class);
        self::assertInstanceOf(WorkerSchedule::class, $provider);

        $recurringMessages = $provider->getSchedule()->getRecurringMessages();

        self::assertCount(6, $recurringMessages);
        $classes = array_map(
            static fn ($recurring) => self::firstMessageClass($recurring),
            $recurringMessages,
        );
        self::assertSame(
            [
                AdvanceRecommendationRuns::class,
                StartDueRecommendationRuns::class,
                RefreshDueFeeds::class,
                PurgeFailedMessages::class,
                SendDueDigests::class,
                SweepSavedSearchMemberships::class,
            ],
            $classes,
        );

        // PeriodicalTrigger::__toString() is what `debug:scheduler` prints as "Trigger": a stable pin on the cadence.
        $frequencies = array_map(
            static fn (RecurringMessage $recurring): string => (string) $recurring->getTrigger(),
            $recurringMessages,
        );
        self::assertSame(
            [
                'every 10 seconds',
                'every 5 minutes',
                'every 5 minutes',
                'every 1 day',
                'every 1 hour',
                'every 1 minute',
            ],
            $frequencies,
        );
    }

    /** Without a persistent pool the daily entry never fires: an in-process checkpoint re-anchors at each restart. */
    public function testTheScheduleKeepsItsCheckpointsInAPersistentPool(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(WorkerSchedule::class);
        self::assertInstanceOf(WorkerSchedule::class, $provider);

        self::assertSame(
            self::getContainer()->get('scheduler.state.cache'),
            $provider->getSchedule()->getState(),
        );
    }

    /**
     * The behavioural proof, through the real MessageGenerator: `debug:scheduler` never calls continue(), so it
     * cannot show this. Each hourly consumer restart is a new generator over one shared pool.
     */
    public function testTheDailyEntryFiresAcrossHourlyConsumerRestarts(): void
    {
        $pool = new ArrayAdapter();
        $clock = new MockClock('2026-08-07 00:00:00');
        $purgesYielded = 0;

        // 25 hourly consumer generations: strictly more than the 24 h the
        // daily entry waits for, and every one of them a fresh process.
        for ($hour = 0; $hour < 25; $hour++) {
            $generator = new MessageGenerator(new WorkerSchedule($pool), 'worker', $clock);
            foreach ($generator->getMessages() as $message) {
                $purgesYielded += $message instanceof PurgeFailedMessages ? 1 : 0;
            }
            $clock->modify('+1 hour');
        }

        self::assertSame(1, $purgesYielded);
    }

    /** A consumer that was down owes the ten-second entry 360 firings; every entry is a sweep, so each fires once. */
    public function testDowntimeIsCaughtUpWithOneFiringPerEntryRatherThanEveryMissedOne(): void
    {
        $pool = new ArrayAdapter();
        $clock = new MockClock('2026-08-07 00:00:00');

        iterator_to_array(
            (new MessageGenerator(new WorkerSchedule($pool), 'worker', $clock))->getMessages(),
            false,
        );

        // Not a whole multiple of ten seconds, so the resumed generator has
        // 360 missed occurrences behind it and none exactly due now.
        $clock->modify('+1 hour +7 seconds');
        $afterDowntime = iterator_to_array(
            (new MessageGenerator(new WorkerSchedule($pool), 'worker', $clock))->getMessages(),
            false,
        );

        $sweeps = array_filter(
            $afterDowntime,
            static fn (object $message): bool => $message instanceof AdvanceRecommendationRuns,
        );
        self::assertCount(1, $sweeps);
    }

    private static function firstMessageClass(RecurringMessage $recurring): string
    {
        $context = new MessageContext(
            'worker',
            $recurring->getId(),
            $recurring->getTrigger(),
            new \DateTimeImmutable(),
        );
        $messages = iterator_to_array($recurring->getProvider()->getMessages($context));

        return $messages[0]::class;
    }
}
