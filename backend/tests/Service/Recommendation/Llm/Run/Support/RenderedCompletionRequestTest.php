<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run\Support;

use App\Service\Recommendation\Llm\Completion\Model\CompletionRequestModel;
use App\Service\Recommendation\Llm\Completion\Model\JsonSchemaModel;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Run\Support\RenderedCompletionRequest;
use PHPUnit\Framework\TestCase;

final class RenderedCompletionRequestTest extends TestCase
{
    public function testTheRequestIsRenderedAsSentWithoutTheTransportFraming(): void
    {
        self::assertSame(
            "{\n    \"model\": \"m\",\n    \"messages\": [\n        {\n            \"role\": \"user\",\n"
            . "            \"content\": \"héllo/wörld\"\n        }\n    ]\n}",
            RenderedCompletionRequest::of(new CompletionRequestModel(
                'm',
                [['role' => 'user', 'content' => 'héllo/wörld']],
                1024,
                new JsonSchemaModel('test', ['type' => 'object']),
                Reasoning::Allowed,
            )),
        );
    }
}
