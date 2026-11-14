<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run\Support;

use App\Service\Recommendation\Llm\Run\Support\ConsolidationShortlist;
use PHPUnit\Framework\TestCase;

final class ConsolidationShortlistTest extends TestCase
{
    public function testTheShortlistKeepsTheBestNEntries(): void
    {
        $ranked = [];
        for ($id = 1; $id <= 20; ++$id) {
            $ranked[] = ['id' => $id, 'score' => 100 - $id, 'reason' => 'r'];
        }

        $cut = ConsolidationShortlist::of($ranked, 6);

        self::assertSame([1, 2, 3, 4, 5, 6], array_column($cut, 'id'));
    }

    public function testAShortPoolStaysWhole(): void
    {
        $ranked = [['id' => 1, 'score' => 5, 'reason' => 'r']];

        self::assertSame($ranked, ConsolidationShortlist::of($ranked, 100));
    }
}
