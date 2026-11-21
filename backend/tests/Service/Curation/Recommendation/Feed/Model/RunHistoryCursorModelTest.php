<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryCursorModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunHistoryCursorModelTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $cursor = new RunHistoryCursorModel(
            new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC')),
            AiRunKind::Story,
            123,
        );

        self::assertSame('1795168800-story-123', $cursor->toCursor());
        self::assertEquals($cursor, RunHistoryCursorModel::parse($cursor->toCursor()));
    }

    public function testParsesTheForYouKindThatHoldsAnUnderscore(): void
    {
        $parsed = RunHistoryCursorModel::parse('1795168800-for_you-7');

        self::assertNotNull($parsed);
        self::assertSame(AiRunKind::ForYou, $parsed->kind);
        self::assertSame(7, $parsed->id);
        self::assertSame(1795168800, $parsed->createdAt->getTimestamp());
    }

    #[DataProvider('malformedCursors')]
    public function testParseRejectsMalformedCursor(string $cursor): void
    {
        self::assertNull(RunHistoryCursorModel::parse($cursor));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'one part' => ['42'];
        yield 'letters' => ['abc'];
        yield 'unknown kind' => ['1795168800-nope-1'];
        yield 'non numeric id' => ['1795168800-story-x'];
        yield 'zero id' => ['1795168800-story-0'];
        yield 'non numeric timestamp' => ['x-story-1'];
        yield 'out of range timestamp' => ['99999999999999999999-story-1'];
        yield 'out of range id' => ['1795168800-story-99999999999999999999'];
    }
}
