<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Jev\Support\NoulScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoulScoreTest extends TestCase
{
    /** @return iterable<string, array{float, int}> */
    public static function nouls(): iterable
    {
        yield 'rounded, not floored' => [0.0737, 74];
        yield 'rounded, not ceiled' => [0.0731, 73];
        yield 'certain yes' => [1.0, 1000];
        yield 'above one is clamped' => [1.2, 1000];
        yield 'below zero is clamped' => [-0.1, 0];
    }

    #[DataProvider('nouls')]
    public function testANoulIsAScoreOnTheRunsScale(float $noul, int $score): void
    {
        self::assertSame($score, NoulScore::of($noul));
    }
}
