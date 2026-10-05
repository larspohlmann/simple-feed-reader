<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ModelDescriptor;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelDescriptorTest extends TestCase
{
    public function testAModelThatSpeaksAScoringProtocolIsAScoringModel(): void
    {
        $model = new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne);

        self::assertSame(RecommendationEngineKind::Scoring, $model->kind());
    }

    /** The id is never read: a model named like Jev without a protocol is an LLM. */
    public function testAModelWithoutAProtocolIsAnLlm(): void
    {
        self::assertSame(RecommendationEngineKind::Llm, (new ModelDescriptor('jev-latest', 32_000))->kind());
    }

    /** @return iterable<string, array{?int}> */
    public static function missingWindows(): iterable
    {
        yield 'none reported' => [null];
        yield 'reported as zero (Respan)' => [0];
    }

    /** A scoring request is budgeted from the window, so a scoring model without one cannot be described. */
    #[DataProvider('missingWindows')]
    public function testAScoringModelNeedsAPositiveContextWindow(?int $contextWindow): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The scoring model "respan/span-01" needs a positive context window.');

        new ModelDescriptor('respan/span-01', $contextWindow, ScoringProtocol::SystemOne);
    }

    public function testAnLlmMayLeaveItsWindowUnreported(): void
    {
        self::assertNull((new ModelDescriptor('gpt-4o', null))->contextWindow);
    }
}
