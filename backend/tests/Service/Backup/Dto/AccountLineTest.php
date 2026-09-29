<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Enum\MagazineStyle;
use App\Service\Backup\Dto\AccountLine;
use PHPUnit\Framework\TestCase;

final class AccountLineTest extends TestCase
{
    public function testItReadsTheMagazineStyle(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'magazineStyle' => 'airy',
        ]);

        self::assertSame(MagazineStyle::Airy, $line->magazineStyle);
    }

    public function testAMissingMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true]);

        self::assertSame(MagazineStyle::Boxed, $line->magazineStyle);
    }

    public function testAnInvalidMagazineStyleFallsBackToBoxed(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'magazineStyle' => 'sideways',
        ]);

        self::assertSame(MagazineStyle::Boxed, $line->magazineStyle);
    }

    /** An older backup's `recommendationSettings` object is ignored, not rejected: the format does not carry it. */
    public function testALegacyRecommendationSettingsBlockIsIgnored(): void
    {
        $line = AccountLine::fromLine([
            'locale' => 'de',
            'scrapeFallbackEnabled' => false,
            'magazineStyle' => 'airy',
            'recommendationSettings' => ['guidancePrompt' => 'Only long reads.', 'batchCount' => 3],
        ]);

        self::assertSame('de', $line->locale);
        self::assertFalse($line->scrapeFallbackEnabled);
        self::assertSame(MagazineStyle::Airy, $line->magazineStyle);
    }
}
