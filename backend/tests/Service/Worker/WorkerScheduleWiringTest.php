<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\Message\PurgeFailedMessages;
use App\Service\Worker\Message\RefreshDueFeeds;
use App\Service\Worker\WorkerSchedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
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

        self::assertCount(3, $recurringMessages);
        $classes = array_map(
            static fn ($recurring) => self::firstMessageClass($recurring),
            $recurringMessages,
        );
        self::assertSame(
            [AdvanceRecommendationRuns::class, RefreshDueFeeds::class, PurgeFailedMessages::class],
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
            ['every 10 seconds', 'every 5 minutes', 'every 1 day'],
            $frequencies,
        );
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
