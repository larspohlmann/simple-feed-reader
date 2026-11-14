<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\TolerantHiddenProfiles;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\TestCase;

final class TolerantHiddenProfilesTest extends TestCase
{
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->logger = new RecordingLogger();
    }

    public function testAnAnswerPassesThrough(): void
    {
        $rusty = new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty');
        $this->hiddenProfiles->hide($rusty);

        $hidden = $this->tolerant()->hiddenAmongOrNone([BlueskyProfiles::RUSTY, 'did:plc:bob'], 'Carrying on.');

        self::assertSame([BlueskyProfiles::RUSTY => $rusty], $hidden);
        self::assertSame([], $this->logger->records);
    }

    public function testNoAnswerIsNoHiddenProfileAndTheCallersWarning(): void
    {
        $failure = new SocialNetworkUnreachableException('down');
        $this->hiddenProfiles->failure = $failure;

        self::assertSame([], $this->tolerant()->hiddenAmongOrNone([BlueskyProfiles::RUSTY], 'Carrying on.'));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertSame('Carrying on.', $this->logger->records[0]['message'] ?? null);
        self::assertSame($failure, $this->logger->records[0]['context']['exception'] ?? null);
    }

    private function tolerant(): TolerantHiddenProfiles
    {
        return new TolerantHiddenProfiles($this->hiddenProfiles, $this->logger);
    }
}
