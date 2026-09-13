<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ParsedCategory;
use App\Service\Parser\ParsedEntry;
use PHPUnit\Framework\TestCase;

final class ParsedEntryTest extends TestCase
{
    public function testCategoriesDefaultToEmptyList(): void
    {
        $entry = new ParsedEntry('guid', null, 'Title', null, null, null, null);

        self::assertSame([], $entry->categories);
    }

    public function testCategoriesArePreserved(): void
    {
        $entry = new ParsedEntry(
            'guid',
            null,
            'Title',
            null,
            null,
            null,
            null,
            categories: [new ParsedCategory('Politics', 'https://example.test/tax')],
        );

        self::assertCount(1, $entry->categories);
        self::assertSame('Politics', $entry->categories[0]->label);
        self::assertSame('https://example.test/tax', $entry->categories[0]->scheme);
    }
}
