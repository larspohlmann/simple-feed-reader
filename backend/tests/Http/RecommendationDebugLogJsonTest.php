<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RecommendationDebugLogJson;
use App\Service\Recommendation\RecommendationDebugLog;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type DebugLogRow from RecommendationDebugLog
 */
final class RecommendationDebugLogJsonTest extends TestCase
{
    public function testAnAccountWithNoRetainedRunGetsAnEmptyPanel(): void
    {
        self::assertSame(
            ['entries' => [], 'run' => null, 'runs' => []],
            RecommendationDebugLogJson::list(RecommendationDebugLog::empty()),
        );
    }

    public function testEachRowCarriesItsStreamingTextOrNull(): void
    {
        $log = new RecommendationDebugLog(
            [self::row(7), self::row(8)],
            [8 => 'partial answer'],
            null,
            [],
        );

        $entries = RecommendationDebugLogJson::list($log)['entries'];

        self::assertNull($entries[0]['streamingText']);
        self::assertSame('partial answer', $entries[1]['streamingText']);
        self::assertSame(7, $entries[0]['id']);
    }

    /** @return DebugLogRow */
    private static function row(int $id): array
    {
        return [
            'id' => $id,
            'runId' => 1,
            'phase' => 'batch',
            'batchNumber' => 1,
            'attempt' => 1,
            'verdict' => null,
            'requestBytes' => 10,
            'responseBytes' => 20,
            'wireBytes' => 30,
            'createdAt' => '2026-08-09T10:00:00+00:00',
            'finishedAt' => null,
            'errorDetail' => null,
            'finishReason' => null,
        ];
    }
}
