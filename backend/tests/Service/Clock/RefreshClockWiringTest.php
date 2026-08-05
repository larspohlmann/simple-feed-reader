<?php

declare(strict_types=1);

namespace App\Tests\Service\Clock;

use App\Service\Clock\DatabaseClock;
use App\Service\EntryIngestor;
use App\Service\FeedScheduler;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Refresh\RefreshRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The refresh pipeline must read and write time through ONE clock. If the
 * writers (FeedScheduler, EntryIngestor) stamp DB time while the runner
 * evaluates the cooldown and the "remaining due" count with the PHP process
 * clock, a skewed process clock (the FastCGI tier runs ~1 h fast) makes a
 * just-fetched feed read as still due — the client's refresh poll never sees
 * remaining reach 0. The suite cannot reproduce that (under SQLite the DB clock
 * is the system clock), so this pins the wiring instead of the behaviour.
 */
final class RefreshClockWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function refreshServices(): iterable
    {
        yield 'scheduler' => [FeedScheduler::class];
        yield 'ingestor' => [EntryIngestor::class];
        yield 'runner' => [RefreshRunner::class];
        yield 'classifier' => [ResponseClassifier::class];
    }

    /**
     * @param class-string $serviceClass
     */
    #[DataProvider('refreshServices')]
    public function testRefreshServiceReadsTheDatabaseClock(string $serviceClass): void
    {
        self::bootKernel();
        $service = self::getContainer()->get($serviceClass);

        $clock = (new \ReflectionProperty($serviceClass, 'clock'))->getValue($service);

        self::assertInstanceOf(DatabaseClock::class, $clock);
    }
}
