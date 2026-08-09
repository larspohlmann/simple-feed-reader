<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use App\Service\Worker\WorkerSchedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Mirrors the house `*WiringTest` convention: pin what the container
 * actually wires, so a refactor cannot silently drop a schedule entry.
 *
 * `RecurringMessage::getMessages()` takes a `MessageContext` in this
 * Symfony version rather than being a no-argument accessor; the context's
 * content is irrelevant here because these are static message providers
 * (see `RecurringMessage::every()`), which ignore it and always yield the
 * same message instance.
 */
final class WorkerScheduleWiringTest extends KernelTestCase
{
    public function testTheWorkerScheduleCarriesExactlyTheDecidedEntries(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(WorkerSchedule::class);
        self::assertInstanceOf(WorkerSchedule::class, $provider);

        $recurringMessages = $provider->getSchedule()->getRecurringMessages();

        self::assertCount(4, $recurringMessages);
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
            ],
            $classes,
        );

        // The class assertion above cannot catch a right-message-wrong-cadence
        // regression (e.g. AdvanceRecommendationRuns running every 10 minutes
        // instead of every 10 seconds): PeriodicalTrigger::__toString() is the
        // same description `debug:scheduler` prints in its "Trigger" column,
        // so it is a stable, meaningful pin on the frequency, not an
        // implementation accident.
        $frequencies = array_map(
            static fn (RecurringMessage $recurring): string => (string) $recurring->getTrigger(),
            $recurringMessages,
        );
        self::assertSame(
            ['every 10 seconds', 'every 5 minutes', 'every 5 minutes', 'every 1 day'],
            $frequencies,
        );
    }

    /**
     * The daily housekeeping entry is unreachable without this: an in-process
     * checkpoint re-anchors every entry to process start, and the consumer is
     * recycled hourly by --time-limit=3600 (#311 final review, Critical 1).
     */
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
     * The behavioural proof behind the wiring assertion above, driven through
     * the real MessageGenerator rather than through `debug:scheduler` -- the
     * command renders next run dates from the checkpoint's *last run* time
     * without calling StatefulTriggerInterface::continue(), so it cannot show
     * this at all.
     *
     * A consumer recycled every hour by --time-limit=3600 is modelled as a
     * new generator per hour over one shared pool. With an in-process
     * checkpoint each of those generators anchored the daily entry at its own
     * start, so the purge was always ~24 h away and never fired; with the
     * pool it comes due 24 h after the FIRST start and is yielded there.
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

    /**
     * The companion to statefulness: a persisted checkpoint means a consumer
     * that was down owes every occurrence it missed, and an hour of downtime
     * owes the ten-second entry 360 firings. All three messages are sweeps,
     * so catching up means running once, now.
     */
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
