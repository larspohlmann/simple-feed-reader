<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Scoring\Support\ScaledScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScaledScoreTest extends TestCase
{
    /** @return iterable<string, array{float, int}> */
    public static function values(): iterable
    {
        yield 'rounded, not floored' => [0.0737, 74];
        yield 'rounded, not ceiled' => [0.0731, 73];
        yield 'the top of the range' => [1.0, 1000];
        yield 'above one is clamped' => [1.2, 1000];
        yield 'below zero is clamped' => [-0.1, 0];
    }

    #[DataProvider('values')]
    public function testAValueIsAScoreOnTheRunsScale(float $value, int $score): void
    {
        self::assertSame($score, ScaledScore::of($value));
    }
}
