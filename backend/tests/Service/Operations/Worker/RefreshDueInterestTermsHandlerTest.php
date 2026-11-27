<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Operations\Worker\Handler\RefreshDueInterestTermsHandler;
use App\Service\Operations\Worker\Message\RefreshDueInterestTerms;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class RefreshDueInterestTermsHandlerTest extends DbTestCase
{
    public function testFiringRunsTheSweepAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        $sweep = self::getContainer()->get(InterestTermSweep::class);
        self::assertInstanceOf(InterestTermSweep::class, $sweep);

        (new RefreshDueInterestTermsHandler($sweep, $logger))(new RefreshDueInterestTerms());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker interest-term refresh finished.', $logger->records[0]['message']);
        self::assertSame(
            ['due' => 0, 'stored' => 0, 'failed' => 0, 'raritiesRefreshed' => 0],
            $logger->records[0]['context']['report'],
        );
    }
}
