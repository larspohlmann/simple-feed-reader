<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Recommendation\Support\PrettyJson;
use PHPUnit\Framework\TestCase;

final class PrettyJsonTest extends TestCase
{
    public function testItIndentsAndKeepsSlashesAndNonAsciiAsTheyAre(): void
    {
        self::assertSame("{\n    \"url\": \"https://a/b\",\n    \"text\": \"Käse — ok\"\n}", PrettyJson::of(
            ['url' => 'https://a/b', 'text' => 'Käse — ok'],
        ));
    }

    public function testItRefusesWhatIsNoValidUtf8(): void
    {
        $this->expectException(\JsonException::class);

        PrettyJson::of("\xB1\x31");
    }
}
