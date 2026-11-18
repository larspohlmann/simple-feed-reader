<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Trending\FallbackChoice;
use App\Service\Curation\Trending\Model\CleanedHeadlineModel;
use App\Service\Curation\Trending\Model\TitleCandidateModel;
use PHPUnit\Framework\TestCase;

final class FallbackChoiceTest extends TestCase
{
    private const int CURRENT = 1;
    private const int BEST = 2;
    private const int EARLIER = 1_000;
    private const int LATER = 2_000;

    public function testTheBestCandidateWinsWithoutACurrentOne(): void
    {
        $chosen = (new FallbackChoice())->choose(
            [self::markerFree(self::CURRENT, 7.0), self::markerFree(self::BEST, 10.0)],
            null,
        );

        self::assertSame(self::BEST, $chosen->entryId);
    }

    public function testTheCurrentStaysWithinEightyPercent(): void
    {
        $chosen = (new FallbackChoice())->choose(
            [self::markerFree(self::CURRENT, 8.0), self::markerFree(self::BEST, 10.0)],
            self::CURRENT,
        );

        self::assertSame(self::CURRENT, $chosen->entryId);
    }

    public function testTheCurrentGivesWayBelowEightyPercent(): void
    {
        $chosen = (new FallbackChoice())->choose(
            [self::markerFree(self::CURRENT, 7.9), self::markerFree(self::BEST, 10.0)],
            self::CURRENT,
        );

        self::assertSame(self::BEST, $chosen->entryId);
    }

    public function testAMarkerFreeCandidateBeatsAHigherMarkedOne(): void
    {
        $chosen = (new FallbackChoice())->choose(
            [self::marked(self::CURRENT, 10.0), self::markerFree(self::BEST, 3.0)],
            null,
        );

        self::assertSame(self::BEST, $chosen->entryId);
    }

    public function testAMarkedCurrentGivesWayToAMarkerFreeOne(): void
    {
        $chosen = (new FallbackChoice())->choose(
            [self::marked(self::CURRENT, 10.0), self::markerFree(self::BEST, 9.0)],
            self::CURRENT,
        );

        self::assertSame(self::BEST, $chosen->entryId);
    }

    public function testATieGoesToTheNewer(): void
    {
        $chosen = (new FallbackChoice())->choose(
            [
                new TitleCandidateModel(self::BEST, new CleanedHeadlineModel('Later', true), 5.0, self::LATER),
                new TitleCandidateModel(self::CURRENT, new CleanedHeadlineModel('Earlier', true), 5.0, self::EARLIER),
            ],
            null,
        );

        self::assertSame(self::BEST, $chosen->entryId);
    }

    private static function markerFree(int $entryId, float $score): TitleCandidateModel
    {
        return new TitleCandidateModel($entryId, new CleanedHeadlineModel('Headline', true), $score, self::EARLIER);
    }

    private static function marked(int $entryId, float $score): TitleCandidateModel
    {
        return new TitleCandidateModel($entryId, new CleanedHeadlineModel('Headline', false), $score, self::EARLIER);
    }
}
