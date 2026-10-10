<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryHeadline;
use PHPUnit\Framework\TestCase;

final class EntryHeadlineTest extends TestCase
{
    public function testKeepsTheStoredTitleAsGivenByTheFeed(): void
    {
        $headline = new EntryHeadline();

        $headline->store('A title');

        self::assertSame('A title', $headline->getTitle());
        self::assertFalse($headline->isDerived());
    }

    public function testRemembersItWasDerived(): void
    {
        $headline = new EntryHeadline();

        $headline->markDerived();

        self::assertTrue($headline->isDerived());
    }

    public function testANewTitleIsNoLongerDerived(): void
    {
        $headline = new EntryHeadline();
        $headline->store('Derived from the post');
        $headline->markDerived();

        $headline->store('Given by the feed');

        self::assertFalse($headline->isDerived());
    }

    public function testATitleStoredAndThenMarkedIsDerived(): void
    {
        $headline = new EntryHeadline();

        $headline->store('Derived from the post');
        $headline->markDerived();

        self::assertTrue($headline->isDerived());
        self::assertSame('Derived from the post', $headline->getTitle());
    }
}
