<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryHeadline;
use App\Entity\Headline;
use PHPUnit\Framework\TestCase;

final class EntryHeadlineTest extends TestCase
{
    public function testKeepsAHeadlineGivenByTheFeed(): void
    {
        $headline = new EntryHeadline();

        $headline->store(Headline::fromFeed('A title'));

        self::assertEquals(Headline::fromFeed('A title'), $headline->read());
    }

    public function testKeepsAHeadlineDerivedFromThePost(): void
    {
        $headline = new EntryHeadline();

        $headline->store(Headline::derived('Derived from the post'));

        self::assertEquals(Headline::derived('Derived from the post'), $headline->read());
    }

    public function testCutsTheTitleToTheColumnLengthInCharacters(): void
    {
        $headline = new EntryHeadline();

        $headline->store(Headline::derived(str_repeat('é', 1025)));

        self::assertEquals(Headline::derived(str_repeat('é', 1024)), $headline->read());
    }
}
