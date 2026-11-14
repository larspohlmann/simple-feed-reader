<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\MastodonVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MastodonVisibilityTest extends TestCase
{
    #[DataProvider('pairs')]
    public function testTheStricterVisibilityWinsOnEitherSide(string $left, string $right, string $stricter): void
    {
        self::assertSame($stricter, MastodonVisibility::stricterOf($left, $right));
        self::assertSame($stricter, MastodonVisibility::stricterOf($right, $left));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function pairs(): iterable
    {
        yield 'same' => ['public', 'public', 'public'];
        yield 'unlisted over public' => ['public', 'unlisted', 'unlisted'];
        yield 'private over unlisted' => ['unlisted', 'private', 'private'];
        yield 'direct over private' => ['private', 'direct', 'direct'];
        yield 'an unknown value counts as public' => ['quiet', 'unlisted', 'unlisted'];
    }
}
