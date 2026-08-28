<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SavedSearch;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SavedSearchTest extends TestCase
{
    public function testIncludeInDigestDefaultsFalseAndToggles(): void
    {
        $search = new SavedSearch(new User('a@b.example', new \DateTimeImmutable()), 'rust', false);

        self::assertFalse($search->isIncludeInDigest());

        $search->setIncludeInDigest(true);
        self::assertTrue($search->isIncludeInDigest());
    }

    public function testPhraseDefaultsFalseAndIsCarried(): void
    {
        $user = new User('a@b.example', new \DateTimeImmutable());

        self::assertFalse((new SavedSearch($user, 'rust', false))->isPhrase());
        self::assertTrue((new SavedSearch($user, 'climate change', false, true))->isPhrase());
    }
}
