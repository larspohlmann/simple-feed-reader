<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\PrettyJson;
use PHPUnit\Framework\TestCase;

final class RerankRequestModelTest extends TestCase
{
    /** Entry 9 before entry 7: the documents keep the batch's order, never the ids' (index i is the i-th document). */
    public function testTheBodyListsTheDocumentsInBatchOrderWithoutTopN(): void
    {
        self::assertSame(
            '{"model":"cohere/rerank-4-fast","query":"Likes Rust.","documents":["Kernel 6.18 — LWN, 2026-10-01.",'
            . '"Soup — Kitchen, 2026-10-02."]}',
            CompactJson::encode(self::request()->payload()),
        );
    }

    public function testTheEntryIdsFollowTheDocuments(): void
    {
        self::assertSame([9, 7], self::request()->entryIds());
    }

    public function testTheRunLogGetsTheBodyPrettyPrinted(): void
    {
        self::assertSame(
            <<<'JSON'
                {
                    "model": "cohere/rerank-4-fast",
                    "query": "Likes Rust.",
                    "documents": [
                        "Kernel 6.18 — LWN, 2026-10-01.",
                        "Soup — Kitchen, 2026-10-02."
                    ]
                }
                JSON,
            PrettyJson::of(self::request()->payload()),
        );
    }

    private static function request(): RerankRequestModel
    {
        return new RerankRequestModel(
            'cohere/rerank-4-fast',
            'Likes Rust.',
            [9 => 'Kernel 6.18 — LWN, 2026-10-01.', 7 => 'Soup — Kitchen, 2026-10-02.'],
        );
    }
}
