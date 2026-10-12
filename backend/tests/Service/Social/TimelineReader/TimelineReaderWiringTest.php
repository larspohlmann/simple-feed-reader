<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineReader;

use App\Service\Social\TimelineReader\NetworkTimelineReader;
use App\Service\Social\TimelineReader\TimelineReaderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TimelineReaderWiringTest extends KernelTestCase
{
    public function testTheContainerResolvesTheReaderWithBothNetworkSources(): void
    {
        self::bootKernel();

        self::assertInstanceOf(NetworkTimelineReader::class, self::getContainer()->get(TimelineReaderInterface::class));
    }
}
