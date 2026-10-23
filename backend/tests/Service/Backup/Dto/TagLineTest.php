<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\TagLine;
use PHPUnit\Framework\TestCase;

final class TagLineTest extends TestCase
{
    public function testALineWithoutAParentKeyReadsAsARoot(): void
    {
        $line = TagLine::fromLine(
            ['kind' => 'tag', 'name' => 'Reading', 'color' => null, 'icon' => null, 'position' => 0],
        );

        self::assertNull($line->parent);
    }

    public function testALineNamingAParentCarriesIt(): void
    {
        $line = TagLine::fromLine(
            [
                'kind' => 'tag', 'name' => 'Long reads', 'color' => null, 'icon' => null, 'position' => 1,
                'parent' => 'Reading',
            ],
        );

        self::assertSame('Reading', $line->parent);
    }
}
