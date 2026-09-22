<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SavedSearchSlug;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class SavedSearchSlugTest extends TestCase
{
    private SavedSearchSlug $slug;

    protected function setUp(): void
    {
        $this->slug = new SavedSearchSlug(new AsciiSlugger());
    }

    public function testPrefixesTheIdAndLowercasesTheTerm(): void
    {
        self::assertSame('42-climate-news', $this->slug->build(42, 'Climate News'));
    }

    public function testTransliteratesNonAsciiAndDropsOperators(): void
    {
        self::assertSame('7-uber-cafe', $this->slug->build(7, 'Über  Café'));
    }

    public function testFallsBackToTheBareIdWhenTheTermSlugifiesEmpty(): void
    {
        self::assertSame('9', $this->slug->build(9, '!!! ***'));
    }
}
