<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ModelDescriptor;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
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
}
