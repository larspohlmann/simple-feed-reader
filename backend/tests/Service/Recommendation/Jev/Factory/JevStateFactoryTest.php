<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Factory;

use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Support\JevArticle;
use App\Service\Recommendation\Jev\Support\SystemOneJson;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Support\TokenEstimate;
use PHPUnit\Framework\TestCase;

final class JevStateFactoryTest extends TestCase
{
    public function testTheStateIsTheProfileThenTheGuidance(): void
    {
        $state = (new JevStateFactory())
            ->create('Likes Rust and homelab posts.', 'More self-hosting, less crypto.', []);

        self::assertSame(
            ['profile' => 'Likes Rust and homelab posts.', 'guidance' => 'More self-hosting, less crypto.'],
            $state,
        );
    }

    public function testWithoutGuidanceTheStateIsTheProfileAlone(): void
    {
        self::assertSame(['profile' => 'Likes Rust.'], (new JevStateFactory())->create('Likes Rust.', null, []));
    }

    /** The guidance stays whole; the profile is cut to exactly what is left: one more character would not fit. */
    public function testAnOverlongProfileIsCutToTheBudgetBesideTheWholeGuidance(): void
    {
        $state = (new JevStateFactory())->create(str_repeat('p', 60_000), 'More self-hosting.', []);

        self::assertSame('More self-hosting.', $state['guidance'] ?? null);
        self::assertLessThanOrEqual(JevStateFactory::STATE_TOKEN_BUDGET, self::tokensOf($state));
        self::assertGreaterThan(
            JevStateFactory::STATE_TOKEN_BUDGET,
            self::tokensOf(['profile' => $state['profile'] . 'p'] + $state),
        );
    }

    /**
     * 4-byte characters: 12 000 of them are 48 000 bytes, over the budget by themselves. The guidance is cut too, and
     * the profile keeps only the bytes the estimate's rounding leaves, less than one token.
     */
    public function testAGuidanceOverTheBudgetByItselfIsCutAndLeavesTheProfileNoWholeToken(): void
    {
        $state = (new JevStateFactory())->create('Likes Rust.', str_repeat('😀', 12_000), []);

        self::assertTrue(str_starts_with('Likes Rust.', $state['profile']));
        self::assertLessThan(4, \strlen($state['profile']));
        self::assertLessThan(12_000, mb_strlen($state['guidance'] ?? ''));
        self::assertLessThanOrEqual(JevStateFactory::STATE_TOKEN_BUDGET, self::tokensOf($state));
    }

    public function testInvalidByteSequencesAreScrubbedFromBoth(): void
    {
        $state = (new JevStateFactory())->create("Likes \xC3 Rust.", "More \xFF homelab.", []);

        self::assertTrue(mb_check_encoding($state['profile'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($state['guidance'] ?? '', 'UTF-8'));
        self::assertStringStartsWith('Likes ', $state['profile']);
    }

    public function testFavoritesFollowTheProfileAndGuidanceInTheCandidateShape(): void
    {
        $favorites = [self::favorite(1, 'Rust 2.0'), self::favorite(2, 'Homelab tour')];

        $state = (new JevStateFactory())->create('Likes Rust.', 'More homelab.', $favorites);

        self::assertSame(['profile', 'guidance', 'favorites'], array_keys($state));
        self::assertSame(array_map(JevArticle::of(...), $favorites), $state['favorites'] ?? null);
    }

    /** Profile and guidance stay whole; the newest favorites fill what is left, whole, and the next would not fit. */
    public function testFavoritesFillOnlyTheBudgetTheProfileAndGuidanceLeave(): void
    {
        $favorites = array_map(
            static fn (int $index): ArticleLineModel => self::favorite($index, 'Favorite ' . $index),
            range(1, 200),
        );

        $state = (new JevStateFactory())->create('Likes Rust.', 'More homelab.', $favorites);

        self::assertSame('Likes Rust.', $state['profile']);
        self::assertSame('More homelab.', $state['guidance'] ?? null);
        $kept = \count($state['favorites'] ?? []);
        self::assertGreaterThan(0, $kept);
        self::assertLessThan(200, $kept);
        self::assertSame(
            array_map(JevArticle::of(...), \array_slice($favorites, 0, $kept)),
            $state['favorites'] ?? null,
        );
        self::assertLessThanOrEqual(JevStateFactory::STATE_TOKEN_BUDGET, self::tokensOf($state));
        $oneMore = ['favorites' => array_map(JevArticle::of(...), \array_slice($favorites, 0, $kept + 1))] + $state;
        self::assertGreaterThan(JevStateFactory::STATE_TOKEN_BUDGET, self::tokensOf($oneMore));
    }

    public function testAProfileFillingTheBudgetLeavesNoFavoritesKey(): void
    {
        $favorites = [self::favorite(1, 'Rust 2.0')];

        $state = (new JevStateFactory())->create(str_repeat('p', 60_000), null, $favorites);

        self::assertArrayNotHasKey('favorites', $state);
    }

    private static function favorite(int $entryId, string $title): ArticleLineModel
    {
        return new ArticleLineModel($entryId, $title, 'Example Feed', '2026-10-01', str_repeat('Body text. ', 50));
    }

    /** @param array<string, mixed> $state */
    private static function tokensOf(array $state): int
    {
        return TokenEstimate::of(SystemOneJson::encode($state));
    }
}
