<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Entity\ImageRendition;
use App\Service\Image\Model\ImageDimensionsModel;
use App\Service\Image\Model\LeadImageModel;
use App\Service\Image\ResizerRungs;
use App\Tests\Support\StubGuardedImageProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResizerRungsTest extends TestCase
{
    private const string LEAD = 'https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=1600';
    private const string CHECK = 'https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=264';

    /** @param list<ImageRendition> $known */
    private static function lead(int $width, array $known = []): LeadImageModel
    {
        return new LeadImageModel(self::LEAD, new ImageDimensionsModel($width, (int) round($width * 0.5)), $known);
    }

    public function testAHostReturningTheAskedWidthResizes(): void
    {
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure(self::CHECK, 264, 132);

        self::assertTrue(new ResizerRungs($probe)->resizes(self::lead(1600)));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function measuredWidths(): iterable
    {
        yield 'one pixel under' => [263, true];
        yield 'one pixel over' => [265, true];
        yield 'two pixels under' => [262, false];
        yield 'two pixels over' => [266, false];
    }

    #[DataProvider('measuredWidths')]
    public function testOnlyOnePixelOfRoundingCounts(int $measuredWidth, bool $expected): void
    {
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure(self::CHECK, $measuredWidth, 132);

        self::assertSame($expected, new ResizerRungs($probe)->resizes(self::lead(1600)));
    }

    public function testAHostReturningTheFullImageDoesNotResize(): void
    {
        $probe = new StubGuardedImageProbe();
        $probe->willMeasure(self::CHECK, 1600, 800);

        self::assertFalse(new ResizerRungs($probe)->resizes(self::lead(1600)));
    }

    public function testAFailedCheckDoesNotResize(): void
    {
        self::assertFalse(new ResizerRungs(new StubGuardedImageProbe())->resizes(self::lead(1600)));
    }

    public function testALeadNarrowerThanTheSmallestTargetIsNotChecked(): void
    {
        $probe = new StubGuardedImageProbe();

        self::assertFalse(new ResizerRungs($probe)->resizes(self::lead(263)));
        self::assertSame([], $probe->requestedUrls());
    }

    public function testALeadAsWideAsTheSmallestTargetIsChecked(): void
    {
        $probe = new StubGuardedImageProbe();

        new ResizerRungs($probe)->resizes(self::lead(264));

        self::assertSame([self::CHECK], $probe->requestedUrls());
    }

    public function testRungsCoverEachUncoveredTargetUpToTheLeadWidth(): void
    {
        $rungs = new ResizerRungs(new StubGuardedImageProbe())->rungsFor(self::lead(800));

        self::assertEquals([
            new ImageRendition('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=264', 264),
            new ImageRendition('https://9to5mac.com/wp-content/uploads/a.jpg?quality=82&w=520', 520),
        ], $rungs);
    }

    public function testNoRungExceedsTheLeadWidth(): void
    {
        $rungs = new ResizerRungs(new StubGuardedImageProbe())->rungsFor(self::lead(1099));

        self::assertSame([264, 520], array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs));
    }

    public function testAKnownRenditionCoversItsTarget(): void
    {
        $known = [new ImageRendition('https://9to5mac.com/a-300.jpg', 300)];

        $rungs = new ResizerRungs(new StubGuardedImageProbe())->rungsFor(self::lead(2000, $known));

        self::assertSame([520, 680, 1100], array_map(static fn (ImageRendition $rung): int => $rung->width, $rungs));
    }
}
