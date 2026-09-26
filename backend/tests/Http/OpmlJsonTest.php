<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\OpmlJson;
use App\Service\Opml\OpmlImportResult;
use PHPUnit\Framework\TestCase;

final class OpmlJsonTest extends TestCase
{
    public function testMapsEveryCount(): void
    {
        self::assertSame(
            ['imported' => 5, 'alreadySubscribed' => 2, 'invalid' => 1, 'skippedOverLimit' => 3],
            OpmlJson::imported(new OpmlImportResult(5, 2, 1, 3)),
        );
    }
}
