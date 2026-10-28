<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\LeadImageModel;
use App\Tests\Support\RenditionProbes;
use App\Tests\Support\StubGuardedImageProbe;
use PHPUnit\Framework\TestCase;

final class RenditionProbeTest extends TestCase
{
    public function testAResizerSkipsGuessing(): void
    {
        $lead = 'https://h.example/wp-content/uploads/a-1152x648.jpg';
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure($lead . '?w=264', 264, 149);

        $rungs = RenditionProbes::over($probe)->rungsFor(
            new LeadImageModel($lead, new ImageDimensionsModel(1152, 648), []),
        );

        self::assertSame(
            [264, 520, 680],
            array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs),
        );
        self::assertSame([$lead . '?w=264'], $probe->requestedUrls());
    }

    public function testWithoutAResizerItGuesses(): void
    {
        $base = 'https://h.example/wp-content/uploads/a';
        $probe = new StubGuardedImageProbe();
        $probe->willExist($base . '-768x432.jpg');

        $rungs = RenditionProbes::over($probe)->rungsFor(
            new LeadImageModel($base . '.jpg', new ImageDimensionsModel(1600, 900), []),
        );

        self::assertEquals([new ImageRendition($base . '-768x432.jpg', 768)], $rungs);
    }
}
