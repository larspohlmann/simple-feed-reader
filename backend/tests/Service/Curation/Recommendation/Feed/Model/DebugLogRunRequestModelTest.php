<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;
use App\Service\Curation\Recommendation\Feed\Model\DebugLogRunRequestModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DebugLogRunRequestModelTest extends TestCase
{
    public function testParsesTheKindAndId(): void
    {
        self::assertEquals(
            new DebugLogRunRequestModel(AiRunKind::Story, 12),
            DebugLogRunRequestModel::parse('story', '12'),
        );
    }

    public function testAnIdWithoutAKindIsAForYouRun(): void
    {
        self::assertEquals(
            new DebugLogRunRequestModel(AiRunKind::ForYou, 12),
            DebugLogRunRequestModel::parse('', '12'),
        );
    }

    public function testAcceptsAnEighteenDigitId(): void
    {
        self::assertEquals(
            new DebugLogRunRequestModel(AiRunKind::ForYou, 123456789012345678),
            DebugLogRunRequestModel::parse('', '123456789012345678'),
        );
    }

    #[DataProvider('provideNoRequest')]
    public function testIsNoRequest(string $kind, string $run): void
    {
        self::assertNull(DebugLogRunRequestModel::parse($kind, $run));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideNoRequest(): iterable
    {
        yield 'no run' => ['story', ''];
        yield 'zero' => ['', '0'];
        yield 'not a number' => ['', '12a'];
        yield 'negative' => ['', '-3'];
        yield 'too long' => ['', '1234567890123456789'];
        yield 'unknown kind' => ['digest', '12'];
    }
}
