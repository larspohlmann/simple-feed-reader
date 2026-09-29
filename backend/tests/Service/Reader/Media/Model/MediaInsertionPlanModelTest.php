<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaInsertionPlanModel;
use App\Service\Reader\Media\Model\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaInsertionPlanModelTest extends TestCase
{
    public function testAnAudioTopPlacementIsNotALeadVisual(): void
    {
        $plan = new MediaInsertionPlanModel(
            [],
            [],
            [new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3')],
        );

        self::assertFalse($plan->topPlacesLeadVisual());
    }

    public function testAVideoTopPlacementIsALeadVisual(): void
    {
        $plan = new MediaInsertionPlanModel([], [], [
            new MediaCandidateModel(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        self::assertTrue($plan->topPlacesLeadVisual());
    }

    public function testAudioBesideAVideoStillCountsAsALeadVisual(): void
    {
        $plan = new MediaInsertionPlanModel([], [], [
            new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3'),
            new MediaCandidateModel(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        self::assertTrue($plan->topPlacesLeadVisual());
    }

    public function testNoTopPlacementIsNotALeadVisual(): void
    {
        self::assertFalse((new MediaInsertionPlanModel([], [], []))->topPlacesLeadVisual());
    }
}
