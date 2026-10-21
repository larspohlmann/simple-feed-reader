<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\FeedPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedPlatformTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function substackGenerators(): iterable
    {
        yield 'as Substack writes it' => ['Substack'];
        yield 'any case, padded' => ['  substack '];
    }

    #[DataProvider('substackGenerators')]
    public function testSubstacksGeneratorNamesSubstack(string $generator): void
    {
        self::assertSame(FeedPlatform::Substack, FeedPlatform::fromGenerator($generator));
    }

    /** @return iterable<string, array{string|null}> */
    public static function otherGenerators(): iterable
    {
        yield 'none' => [null];
        yield 'WordPress' => ['https://wordpress.org/?v=6.6'];
        yield 'Substack only as a part' => ['Substack Clone 2.0'];
    }

    #[DataProvider('otherGenerators')]
    public function testAnyOtherGeneratorNamesNoPlatform(?string $generator): void
    {
        self::assertNull(FeedPlatform::fromGenerator($generator));
    }
}
