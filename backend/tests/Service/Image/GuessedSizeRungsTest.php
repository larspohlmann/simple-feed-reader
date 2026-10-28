<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\GuessedSizeRungs;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\LeadImageModel;
use App\Tests\Support\StubGuardedImageProbe;
use PHPUnit\Framework\TestCase;

final class GuessedSizeRungsTest extends TestCase
{
    private static function lead(string $url, int $width, int $height): LeadImageModel
    {
        return new LeadImageModel($url, new ImageDimensionsModel($width, $height), []);
    }

    /**
     * @param list<ImageRendition> $rungs
     *
     * @return list<int>
     */
    private static function widths(array $rungs): array
    {
        return array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs);
    }

    public function testAnUnsuffixedWordPressOriginalWalksItsOwnAspect(): void
    {
        $base = 'https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept';
        $probe = new StubGuardedImageProbe();
        $probe->willExist($base . '-768x403.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '.jpg', 1200, 630));

        self::assertEquals([new ImageRendition($base . '-768x403.jpg', 768)], $rungs);
        self::assertSame([
            $base . '-300x158.jpg',
            $base . '-384x202.jpg',
            $base . '-512x269.jpg',
            $base . '-640x336.jpg',
            $base . '-768x403.jpg',
        ], $probe->requestedUrls());
    }

    public function testASuffixedCropUsesItsMeasuredOriginal(): void
    {
        $base = 'https://www.smarticular.net/wp-content/uploads/2026/10/broeselknoedel-rezept';
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure($base . '.jpg', 1200, 630);
        $probe->willExist($base . '-768x403.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '-202x202.jpg', 202, 202));

        self::assertSame([768, 1200], self::widths(ImageRendition::ladder($rungs)));
    }

    public function testAnOriginalWiderThanTheLargestTargetIsNoRung(): void
    {
        $base = 'https://cdn.arstechnica.net/wp-content/uploads/2026/10/anthropic-crystal-ball';
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure($base . '.jpg', 2560, 1440);
        $probe->willExist($base . '-640x360.jpg');
        $probe->willExist($base . '-768x432.jpg');
        $probe->willExist($base . '-1280x720.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '-1152x648.jpg', 1152, 648));

        self::assertSame([640, 768], self::widths(ImageRendition::ladder($rungs)));
        self::assertNotContains($base . '-1280x720.jpg', $probe->requestedUrls());
    }

    public function testWithoutAnOriginalTheSuffixedLeadIsTheBasis(): void
    {
        $base = 'https://bilder.deutschlandfunk.de/15/1b/gadi-eisenkot-104';
        $probe = new StubGuardedImageProbe();
        $probe->willExist($base . '-512x512.jpg');
        $probe->willExist($base . '-768x768.jpg');
        $probe->willExist($base . '-1280x1280.jpg');

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead($base . '-1920x1920.jpg', 1920, 1920));

        self::assertSame([512, 768, 1280], self::widths(ImageRendition::ladder($rungs)));
        self::assertNotContains($base . '-1024x1024.jpg', $probe->requestedUrls());
    }

    public function testACoveredBandIsNotProbed(): void
    {
        $base = 'https://h.example/wp-content/uploads/a';
        $probe = new StubGuardedImageProbe();
        $lead = new LeadImageModel(
            $base . '.jpg',
            new ImageDimensionsModel(1000, 500),
            [new ImageRendition($base . '-300x150.jpg', 300)],
        );

        new GuessedSizeRungs($probe)->rungsFor($lead);

        self::assertSame([$base . '-640x320.jpg'], $probe->requestedUrls());
    }

    public function testAnUnsuffixedLeadOutsideWordPressIsLeftAlone(): void
    {
        $probe = new StubGuardedImageProbe();

        $rungs = new GuessedSizeRungs($probe)->rungsFor(self::lead('https://i.guim.co.uk/a.jpg', 1200, 800));

        self::assertSame([], $rungs);
        self::assertSame([], $probe->requestedUrls());
    }
}
