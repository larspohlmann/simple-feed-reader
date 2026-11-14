<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use App\Service\Recommendation\Support\CompactJson;
use App\Service\Recommendation\Support\TokenEstimate;
use PHPUnit\Framework\TestCase;

final class ScoringStateFactoryTest extends TestCase
{
    private const int BUDGET = 10_000;

    public function testTheStateIsTheProfileThenTheGuidance(): void
    {
        $state = self::state('Likes Rust and homelab posts.', 'More self-hosting, less crypto.', []);

        self::assertSame(
            ['profile' => 'Likes Rust and homelab posts.', 'guidance' => 'More self-hosting, less crypto.'],
            $state,
        );
    }

    public function testWithoutGuidanceTheStateIsTheProfileAlone(): void
    {
        self::assertSame(['profile' => 'Likes Rust.'], self::state('Likes Rust.', null, []));
    }

    /** The guidance stays whole; the profile is cut to exactly what is left: one more character would not fit. */
    public function testAnOverlongProfileIsCutToTheBudgetBesideTheWholeGuidance(): void
    {
        $state = self::state(str_repeat('p', 60_000), 'More self-hosting.', []);

        self::assertSame('More self-hosting.', $state['guidance'] ?? null);
        self::assertLessThanOrEqual(self::BUDGET, self::tokensOf($state));
        self::assertGreaterThan(self::BUDGET, self::tokensOf(['profile' => $state['profile'] . 'p'] + $state));
    }

    /**
     * 4-byte characters: 12 000 of them are 48 000 bytes, over the budget by themselves. The guidance is cut too, and
     * the profile keeps only the bytes the estimate's rounding leaves, less than one token.
     */
    public function testAGuidanceOverTheBudgetByItselfIsCutAndLeavesTheProfileNoWholeToken(): void
    {
        $state = self::state('Likes Rust.', str_repeat('😀', 12_000), []);

        self::assertTrue(str_starts_with('Likes Rust.', $state['profile']));
        self::assertLessThan(4, \strlen($state['profile']));
        self::assertLessThan(12_000, mb_strlen($state['guidance'] ?? ''));
        self::assertLessThanOrEqual(self::BUDGET, self::tokensOf($state));
    }

    public function testInvalidByteSequencesAreScrubbedFromBoth(): void
    {
        $state = self::state("Likes \xC3 Rust.", "More \xFF homelab.", []);

        self::assertTrue(mb_check_encoding($state['profile'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($state['guidance'] ?? '', 'UTF-8'));
        self::assertStringStartsWith('Likes ', $state['profile']);
    }

    public function testFavoritesFollowTheProfileAndGuidanceInTheCandidateShape(): void
    {
        $favorites = [self::favorite(1, 'Rust 2.0'), self::favorite(2, 'Homelab tour')];

        $state = self::state('Likes Rust.', 'More homelab.', $favorites);

        self::assertSame(['profile', 'guidance', 'favorites'], array_keys($state));
        self::assertSame(array_map(ScoringArticle::of(...), $favorites), $state['favorites'] ?? null);
    }

    /** Profile and guidance stay whole; the newest favorites fill what is left, whole, and the next would not fit. */
    public function testFavoritesFillOnlyTheBudgetTheProfileAndGuidanceLeave(): void
    {
        $favorites = array_map(
            static fn (int $index): ArticleLineModel => self::favorite($index, 'Favorite ' . $index),
            range(1, 200),
        );

        $state = self::state('Likes Rust.', 'More homelab.', $favorites);

        self::assertSame('Likes Rust.', $state['profile']);
        self::assertSame('More homelab.', $state['guidance'] ?? null);
        $kept = \count($state['favorites'] ?? []);
        self::assertGreaterThan(0, $kept);
        self::assertLessThan(200, $kept);
        self::assertSame(
            array_map(ScoringArticle::of(...), \array_slice($favorites, 0, $kept)),
            $state['favorites'] ?? null,
        );
        self::assertLessThanOrEqual(self::BUDGET, self::tokensOf($state));
        $oneMore = ['favorites' => array_map(ScoringArticle::of(...), \array_slice($favorites, 0, $kept + 1))] + $state;
        self::assertGreaterThan(self::BUDGET, self::tokensOf($oneMore));
    }

    public function testAProfileFillingTheBudgetLeavesNoFavoritesKey(): void
    {
        $favorites = [self::favorite(1, 'Rust 2.0')];

        $state = self::state(str_repeat('p', 60_000), null, $favorites);

        self::assertArrayNotHasKey('favorites', $state);
    }

    /** 9 tokens hold 35 bytes: `{"profile":"` and `"}` leave 21 characters of the profile. */
    public function testTheStateFitsTheBudgetItIsGiven(): void
    {
        $state = (new ScoringStateFactory())->create(
            new ScoringReaderModel('Likes Rust and homelab posts.', null, []),
            9,
        );

        self::assertSame(['profile' => 'Likes Rust and homela'], $state);
    }

    /** The budget holds the first and last favorite exactly; the middle one does not fit, so the last is not tried. */
    public function testTheFirstFavoriteThatDoesNotFitEndsTheFavorites(): void
    {
        $newest = new ArticleLineModel(1, 'Rust 2.0', 'Feed', '2026-10-01', null);
        $overlong = self::favorite(2, str_repeat('Homelab tour ', 20));
        $oldest = new ArticleLineModel(3, 'Kernel', 'Feed', '2026-10-01', null);
        $budget = self::tokensOf(
            ['profile' => 'Likes Rust.', 'favorites' => array_map(ScoringArticle::of(...), [$newest, $oldest])],
        );

        $state = (new ScoringStateFactory())->create(
            new ScoringReaderModel('Likes Rust.', null, [$newest, $overlong, $oldest]),
            $budget,
        );

        self::assertSame([ScoringArticle::of($newest)], $state['favorites'] ?? null);
    }

    /**
     * @param list<ArticleLineModel> $favorites
     *
     * @return array{profile: string, guidance?: string, favorites?: non-empty-list<array<string, string>>}
     */
    private static function state(string $profile, ?string $guidance, array $favorites): array
    {
        return (new ScoringStateFactory())->create(
            new ScoringReaderModel($profile, $guidance, $favorites),
            self::BUDGET,
        );
    }

    private static function favorite(int $entryId, string $title): ArticleLineModel
    {
        return new ArticleLineModel($entryId, $title, 'Example Feed', '2026-10-01', str_repeat('Body text. ', 50));
    }

    /** @param array<string, mixed> $state */
    private static function tokensOf(array $state): int
    {
        return TokenEstimate::of(CompactJson::encode($state));
    }
}
