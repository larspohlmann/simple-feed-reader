<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\LinkCardDrainJson;
use App\Service\Content\LinkCard\Model\LinkCardDrainReportModel;
use PHPUnit\Framework\TestCase;

final class LinkCardDrainJsonTest extends TestCase
{
    public function testItSendsTheCompletedCountAndWhetherTheQueueWasReached(): void
    {
        self::assertSame(
            ['completed' => 12, 'caughtUp' => false],
            LinkCardDrainJson::report(new LinkCardDrainReportModel(12, false)),
        );
    }
}
