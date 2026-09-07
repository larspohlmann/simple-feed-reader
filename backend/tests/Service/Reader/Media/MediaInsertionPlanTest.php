<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaInsertionPlan;
use App\Service\Reader\Media\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaInsertionPlanTest extends TestCase
{
    public function testAnAudioTopPlacementIsNotALeadVisual(): void
    {
        $plan = new MediaInsertionPlan([], [], [new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        self::assertFalse($plan->topPlacesLeadVisual());
    }

    public function testAVideoTopPlacementIsALeadVisual(): void
    {
        $plan = new MediaInsertionPlan([], [], [
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        self::assertTrue($plan->topPlacesLeadVisual());
    }

    public function testAudioBesideAVideoStillCountsAsALeadVisual(): void
    {
        $plan = new MediaInsertionPlan([], [], [
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        self::assertTrue($plan->topPlacesLeadVisual());
    }

    public function testNoTopPlacementIsNotALeadVisual(): void
    {
        self::assertFalse((new MediaInsertionPlan([], [], []))->topPlacesLeadVisual());
    }
}
