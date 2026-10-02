<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev;

use App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Jev\JevBatchPacker;
use App\Service\Recommendation\Jev\Support\SystemOneJson;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Support\TokenEstimate;
use PHPUnit\Framework\TestCase;

final class JevBatchPackerTest extends TestCase
{
    /** Short Latin articles fit the token budget by far: the question cap closes each request. */
    public function testShortArticlesFillRequestsUpToTheQuestionCapInPoolOrder(): void
    {
        $candidates = $this->candidates(250, 'Short title', 'Short description.');

        $batches = $this->packer()->pack($candidates);

        self::assertSame([100, 100, 50], array_map(\count(...), $batches));
        self::assertSame(range(1, 250), array_merge(...$batches));
    }

    /**
     * Three-byte characters in every field, 731 tokens a question: 26,000 tokens (32k less 2k framing and 4k state)
     * hold 35 of them, so the token budget closes each request before the question cap.
     */
    public function testHeavyArticlesFillRequestsUpToTheTokenBudget(): void
    {
        $candidates = $this->candidates(120, str_repeat('漢', 300), str_repeat('漢', 600));

        $batches = $this->packer()->pack($candidates);

        self::assertSame([35, 35, 35, 15], array_map(\count(...), $batches));
        self::assertSame(range(1, 120), array_merge(...$batches));
        $factory = new SystemOneRequestFactory();
        foreach ($batches as $batch) {
            $tokens = 0;
            foreach ($batch as $entryId) {
                $tokens += TokenEstimate::of(SystemOneJson::encode($factory->question($candidates[$entryId - 1])));
            }
            self::assertLessThanOrEqual(26_000, $tokens);
        }
    }

    /** @return list<ArticleLineModel> entry ids 1…$count */
    private function candidates(int $count, string $title, string $description): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, $title, 'Feed', '2026-10-01', $description),
            range(1, $count),
        );
    }

    private function packer(): JevBatchPacker
    {
        return new JevBatchPacker(new SystemOneRequestFactory());
    }
}
