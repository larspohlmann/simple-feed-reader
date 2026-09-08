<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\AccountLine;
use App\Service\Reader\MagazineStyle;
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

    /**
     * A backup written before #935 carries a `recommendationSettings` object.
     * The format no longer reads it (#935 dropped "For you" settings from the
     * backup), so the extra key must be ignored, not rejected.
     */
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
