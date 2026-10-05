<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Scoring\Support\ProbabilityScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProbabilityScoreTest extends TestCase
{
    /** @return iterable<string, array{float, int}> */
    public static function probabilities(): iterable
    {
        yield 'rounded, not floored' => [0.0737, 74];
        yield 'rounded, not ceiled' => [0.0731, 73];
        yield 'certain yes' => [1.0, 1000];
        yield 'above one is clamped' => [1.2, 1000];
        yield 'below zero is clamped' => [-0.1, 0];
    }

    #[DataProvider('probabilities')]
    public function testAProbabilityIsAScoreOnTheRunsScale(float $probability, int $score): void
    {
        self::assertSame($score, ProbabilityScore::of($probability));
    }
}
