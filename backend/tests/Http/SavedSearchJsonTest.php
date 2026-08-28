<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use PHPUnit\Framework\TestCase;

final class SavedSearchJsonTest extends TestCase
{
    public function testOneEmitsIncludeInDigest(): void
    {
        $search = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'rust', false);
        $search->setIncludeInDigest(true);

        $json = SavedSearchJson::one($search, [7, 8]);

        self::assertTrue($json['includeInDigest']);
        self::assertSame([7, 8], $json['unreadEntryIds']);
    }

    public function testOneEmitsThePhraseFlag(): void
    {
        $substring = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'climate change', false, false);
        $phrase = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'climate change', false, true);

        self::assertFalse(SavedSearchJson::one($substring, [])['phrase']);
        self::assertTrue(SavedSearchJson::one($phrase, [])['phrase']);
    }
}
