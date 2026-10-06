<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\PrettyJson;
use PHPUnit\Framework\TestCase;

final class SystemOneRequestModelTest extends TestCase
{
    public function testTheBodyIsCompactWithSlashesAndUmlautsAsTheyAre(): void
    {
        self::assertSame(
            '{"model":"jev-latest","state":{"profile":"Kernel/Rust, Größe"},"questions":{"entry-1":{"type":"noul"}}}',
            CompactJson::encode($this->request()->payload()),
        );
    }

    public function testTheRenderedRequestIsTheSameBodyPrettyPrinted(): void
    {
        self::assertSame(
            "{\n    \"model\": \"jev-latest\",\n    \"state\": {\n        \"profile\": \"Kernel/Rust, Größe\"\n    },\n"
            . "    \"questions\": {\n        \"entry-1\": {\n            \"type\": \"noul\"\n        }\n    }\n}",
            PrettyJson::of($this->request()->payload()),
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
