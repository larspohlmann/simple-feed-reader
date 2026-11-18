<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\MostShared;
use PHPUnit\Framework\TestCase;

final class MostSharedTest extends TestCase
{
    public function testTheMostSharedWins(): void
    {
        $shares = ['newest' => 2, 'middle' => 3, 'oldest' => 1];

        self::assertSame('middle', MostShared::pick(array_keys($shares), self::sharesOf($shares)));
    }

    public function testATieGoesToTheNewest(): void
    {
        $shares = ['newest' => 2, 'newer' => 3, 'oldest' => 3];

        self::assertSame('newer', MostShared::pick(array_keys($shares), self::sharesOf($shares)));
    }

    /**
     * @param array<string, int> $shares
     *
     * @return \Closure(string): int
     */
    private static function sharesOf(array $shares): \Closure
    {
        return static fn (string $card): int => $shares[$card];
    }
}
