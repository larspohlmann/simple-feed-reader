<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Factory;

use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Support\JevTokenEstimate;
use PHPUnit\Framework\TestCase;

final class JevStateFactoryTest extends TestCase
{
    public function testTheStateIsTheProfileThenTheGuidance(): void
    {
        $state = (new JevStateFactory())->create('Likes Rust and homelab posts.', 'More self-hosting, less crypto.');

        self::assertSame(
            ['profile' => 'Likes Rust and homelab posts.', 'guidance' => 'More self-hosting, less crypto.'],
            $state,
        );
    }

    public function testWithoutGuidanceTheStateIsTheProfileAlone(): void
    {
        self::assertSame(['profile' => 'Likes Rust.'], (new JevStateFactory())->create('Likes Rust.', null));
    }

    /** The guidance stays whole; the profile is cut to exactly what is left: one more character would not fit. */
    public function testAnOverlongProfileIsCutToTheBudgetBesideTheWholeGuidance(): void
    {
        $state = (new JevStateFactory())->create(str_repeat('p', 20_000), 'More self-hosting.');

        self::assertSame('More self-hosting.', $state['guidance']);
        self::assertLessThanOrEqual(JevStateFactory::STATE_TOKEN_BUDGET, JevTokenEstimate::ofJson($state));
        self::assertGreaterThan(
            JevStateFactory::STATE_TOKEN_BUDGET,
            JevTokenEstimate::ofJson(['profile' => $state['profile'] . 'p'] + $state),
        );
    }

    /**
     * 4-byte characters: 4 000 of them are 16 000 bytes, over the budget by themselves. The guidance is cut too, and
     * the profile keeps only the bytes the estimate's rounding leaves, less than one token.
     */
    public function testAGuidanceOverTheBudgetByItselfIsCutAndLeavesTheProfileNoWholeToken(): void
    {
        $state = (new JevStateFactory())->create('Likes Rust.', str_repeat('😀', 4_000));

        self::assertTrue(str_starts_with('Likes Rust.', $state['profile']));
        self::assertLessThan(4, \strlen($state['profile']));
        self::assertLessThan(4_000, mb_strlen($state['guidance']));
        self::assertLessThanOrEqual(JevStateFactory::STATE_TOKEN_BUDGET, JevTokenEstimate::ofJson($state));
    }

    public function testInvalidByteSequencesAreScrubbedFromBoth(): void
    {
        $state = (new JevStateFactory())->create("Likes \xC3 Rust.", "More \xFF homelab.");

        self::assertTrue(mb_check_encoding($state['profile'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($state['guidance'], 'UTF-8'));
        self::assertStringStartsWith('Likes ', $state['profile']);
    }
}
