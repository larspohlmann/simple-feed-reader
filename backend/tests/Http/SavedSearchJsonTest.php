<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use App\Service\Search\SavedSearchTally;
use PHPUnit\Framework\TestCase;

final class SavedSearchJsonTest extends TestCase
{
    public function testOneEmitsIncludeInDigest(): void
    {
        $search = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'rust', false);
        $search->setIncludeInDigest(true);

        $json = SavedSearchJson::one($search, new SavedSearchTally([7, 8], 5));

        self::assertTrue($json['includeInDigest']);
        self::assertSame([7, 8], $json['unreadEntryIds']);
        self::assertSame(5, $json['memberCount']);
    }

    public function testOneEmitsThePhraseFlag(): void
    {
        $substring = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'climate change', false, false);
        $phrase = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'climate change', false, true);

        self::assertFalse(SavedSearchJson::one($substring, new SavedSearchTally([], 0))['phrase']);
        self::assertTrue(SavedSearchJson::one($phrase, new SavedSearchTally([], 0))['phrase']);
    }
}
