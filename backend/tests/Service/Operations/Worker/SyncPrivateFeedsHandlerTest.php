<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Worker\Handler\SyncPrivateFeedsHandler;
use App\Service\Worker\Message\SyncPrivateFeeds;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedSyncWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubSocialCandidates;
use Symfony\Component\Clock\MockClock;

final class SyncPrivateFeedsHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $sweep = PrivateFeedSyncWiring::sweep(
            self::getContainer(),
            $this->entityManager,
            new MockClock('2026-10-17 12:00:00', 'UTC'),
            new StubSocialCandidates(new SocialNetworkUnreachableException('no network in this test')),
        );
        $logger = new RecordingLogger();

        (new SyncPrivateFeedsHandler($sweep, $logger))(new SyncPrivateFeeds());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker private feed sync finished.', $logger->records[0]['message']);
        self::assertSame(
            ['checked' => 0, 'skipped' => 0, 'added' => 0, 'removed' => 0],
            $logger->records[0]['context']['report'],
        );
    }
}
