<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use PHPUnit\Framework\TestCase;

final class SystemOneRequestModelTest extends TestCase
{
    public function testTheBodyIsCompactWithSlashesAndUmlautsAsTheyAre(): void
    {
        self::assertSame(
            '{"model":"jev-latest","state":{"profile":"Kernel/Rust, Größe"},"questions":{"entry-1":{"type":"noul"}}}',
            $this->request()->toRequestBody(),
        );
    }

    public function testTheRenderedRequestIsTheSameBodyPrettyPrinted(): void
    {
        self::assertSame(
            "{\n    \"model\": \"jev-latest\",\n    \"state\": {\n        \"profile\": \"Kernel/Rust, Größe\"\n    },\n"
            . "    \"questions\": {\n        \"entry-1\": {\n            \"type\": \"noul\"\n        }\n    }\n}",
            $this->request()->toRenderedRequest(),
        );
    }

    private function request(): SystemOneRequestModel
    {
        return new SystemOneRequestModel(
            'jev-latest',
            ['profile' => 'Kernel/Rust, Größe'],
            ['entry-1' => ['type' => 'noul']],
        );
    }
}
