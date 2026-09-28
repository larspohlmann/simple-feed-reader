<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Model;

use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\TestCase;

final class ParsedEntryModelTest extends TestCase
{
    public function testCategoriesDefaultToEmptyList(): void
    {
        $entry = new ParsedEntryModel('guid', null, 'Title', null, null, null, null);

        self::assertSame([], $entry->categories);
    }

    public function testCategoriesArePreserved(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            null,
            'Title',
            null,
            null,
            null,
            null,
            categories: [new ParsedCategoryModel('Politics', 'https://example.test/tax')],
        );

        self::assertCount(1, $entry->categories);
        self::assertSame('Politics', $entry->categories[0]->label);
        self::assertSame('https://example.test/tax', $entry->categories[0]->scheme);
    }
}
