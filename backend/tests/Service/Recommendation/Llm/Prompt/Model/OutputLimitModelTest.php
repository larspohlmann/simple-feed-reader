<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Prompt\Model;

use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Model\OutputLimitModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutputLimitModelTest extends TestCase
{
    /** @return iterable<string, array{?int, int, int}> */
    public static function clampCases(): iterable
    {
        yield 'no cap leaves it alone' => [null, 5000, 5000];
        yield 'under the cap' => [null, 1, 1];
        yield 'a cap above' => [5001, 5000, 5000];
        yield 'a cap equal' => [5000, 5000, 5000];
        yield 'a cap below' => [4999, 5000, 4999];
    }

    #[DataProvider('clampCases')]
    public function testClampKeepsTheSmallerOfTokensAndCap(?int $cap, int $tokens, int $expected): void
    {
        self::assertSame($expected, (new OutputLimitModel(Reasoning::Allowed, $cap))->clamp($tokens));
    }

    /** @return iterable<string, array{?int, int, bool}> */
    public static function admitsCases(): iterable
    {
        yield 'no cap admits anything' => [null, 10_500, true];
        yield 'a cap exactly the size' => [10_500, 10_500, true];
        yield 'a cap one token smaller' => [10_499, 10_500, false];
        yield 'a cap above' => [10_501, 10_500, true];
    }

    #[DataProvider('admitsCases')]
    public function testAdmitsTokensUpToTheCap(?int $cap, int $tokens, bool $expected): void
    {
        self::assertSame($expected, (new OutputLimitModel(Reasoning::Suppressed, $cap))->admits($tokens));
    }
}
