<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Service\Social\ProfileImageSweep;
use App\Service\Worker\Handler\RefreshProfileImagesHandler;
use App\Service\Worker\Message\RefreshProfileImages;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;

final class RefreshProfileImagesHandlerTest extends DbTestCase
{
    public function testFiringSweepsAndLogsTheReport(): void
    {
        $sweep = self::getContainer()->get(ProfileImageSweep::class);
        self::assertInstanceOf(ProfileImageSweep::class, $sweep);
        $logger = new RecordingLogger();

        (new RefreshProfileImagesHandler($sweep, $logger))(new RefreshProfileImages());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker profile picture sweep finished.', $logger->records[0]['message']);
        self::assertSame(['checked' => 0, 'changed' => 0], $logger->records[0]['context']['report']);
    }
}
