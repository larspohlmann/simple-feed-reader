<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Support;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Service\Search\Support\SavedSearchTerms;
use PHPUnit\Framework\TestCase;

final class SavedSearchTermsTest extends TestCase
{
    public function testAPlainSearchReadsAsEachWordMatchedAnywhere(): void
    {
        $terms = SavedSearchTerms::of($this->search('climate change', wholeWord: false, phrase: false));

        self::assertSame(['climate', 'change'], $terms->terms);
        self::assertFalse($terms->isWholeWord);
        self::assertFalse($terms->isPhrase);
    }

    public function testAWholeWordSearchReadsAsEachWordMatchedOnABoundary(): void
    {
        $terms = SavedSearchTerms::of($this->search('climate change', wholeWord: true, phrase: false));

        self::assertSame(['climate', 'change'], $terms->terms);
        self::assertTrue($terms->isWholeWord);
        self::assertFalse($terms->isPhrase);
    }

    public function testAPhraseSearchReadsAsOneExactPhrase(): void
    {
        $terms = SavedSearchTerms::of($this->search('climate  change', wholeWord: false, phrase: true));

        self::assertSame(['climate change'], $terms->terms);
        self::assertFalse($terms->isWholeWord);
        self::assertTrue($terms->isPhrase);
    }

    public function testAPhraseWinsOverWholeWordWhenBothColumnsAreSet(): void
    {
        $terms = SavedSearchTerms::of($this->search('climate change', wholeWord: true, phrase: true));

        self::assertSame(['climate change'], $terms->terms);
        self::assertTrue($terms->isPhrase);
        self::assertFalse($terms->isWholeWord);
    }

    public function testTheTermPairsTheSearchIdWithItsTerms(): void
    {
        $search = $this->search('climate', wholeWord: true, phrase: false);
        (new \ReflectionProperty(SavedSearch::class, 'id'))->setValue($search, 42);

        $term = SavedSearchTerms::termOf($search);

        self::assertSame(42, $term->id);
        self::assertSame(['climate'], $term->terms->terms);
        self::assertTrue($term->terms->isWholeWord);
    }

    private function search(string $term, bool $wholeWord, bool $phrase): SavedSearch
    {
        $user = new User('terms@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        return new SavedSearch($user, $term, $wholeWord, $phrase);
    }
}
