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
}
