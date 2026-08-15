<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * The LIKE search over title and summary. Every assertion here uses ASCII
 * terms on purpose: MySQL's collation folds case and accents, SQLite's LIKE
 * folds ASCII case only, and this suite runs on SQLite natively. A test that
 * searched "Ubung" for "Übung" would pass in production and fail here.
 */
final class EntrySearchTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->em->flush();
    }

    private function entry(
        string $guid,
        string $title,
        ?string $summary = null,
        string $effectiveDate = '2026-07-10T00:00:00Z',
    ): Entry {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $entry->setSummary($summary);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function repo(): EntryRepository
    {
        $repo = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repo);

        return $repo;
    }

    /** @return list<string> the guids the search returned, in order */
    private function search(string $input, ?EntryCursor $cursor = null, int $limit = 50): array
    {
        $rows = $this->repo()->searchForUser(new EntrySearchQuery(
            userId: $this->user->getId() ?? 0,
            terms: SearchTerms::fromInput($input),
            cursor: $cursor,
            limit: $limit,
        ));

        return array_map(static fn ($row) => $row->entry->getGuid(), $rows);
    }

    public function testMatchesTheTitle(): void
    {
        $this->entry('hit', 'Angular 20 ships');
        $this->entry('miss', 'Something else');

        self::assertSame(['hit'], $this->search('angular'));
    }

    public function testMatchesTheSummary(): void
    {
        $this->entry('hit', 'Untitled', 'A walk through angular signals');
        $this->entry('miss', 'Untitled', 'A walk through something else');

        self::assertSame(['hit'], $this->search('angular'));
    }

    public function testIgnoresCaseForAnAsciiTerm(): void
    {
        $this->entry('hit', 'ANGULAR ships');

        self::assertSame(['hit'], $this->search('angular'));
    }

    public function testRequiresEveryTermToMatch(): void
    {
        $this->entry('both', 'Angular signals explained');
        $this->entry('one', 'Angular routing explained');

        self::assertSame(['both'], $this->search('angular signals'));
    }

    public function testEachTermIsBoundToItsOwnParameter(): void
    {
        // Neither entry carries both terms, so the correct AND-of-terms query
        // matches nothing. If every term's LIKE reused the same bound
        // parameter name, all placeholders would silently collapse onto the
        // last term's value, and the entry that happens to match only that
        // last term would wrongly come back.
        $this->entry('first-term-only', 'Angular explained');
        $this->entry('second-term-only', 'Signals explained');

        self::assertSame([], $this->search('angular signals'));
    }

    public function testMatchesTermsAcrossTitleAndSummary(): void
    {
        $this->entry('split', 'Angular 20 ships', 'The whole story about signals');

        self::assertSame(['split'], $this->search('angular signals'));
    }

    public function testSkipsEntriesInFeedsTheUserDoesNotSubscribeTo(): void
    {
        $other = new Feed('https://other.example.com/feed.xml');
        $this->em->persist($other);
        $foreign = new Entry(
            $other,
            'foreign',
            'https://other.example.com/foreign',
            'Angular elsewhere',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($foreign);
        $this->em->flush();

        self::assertSame([], $this->search('angular'));
    }

    public function testReturnsNewestFirst(): void
    {
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');

        self::assertSame(['newer', 'older'], $this->search('angular'));
    }

    public function testPagesWithTheKeysetCursor(): void
    {
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $newer = $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');

        $cursor = new EntryCursor($newer->getEffectiveDate(), $newer->getId() ?? 0);

        self::assertSame(['older'], $this->search('angular', $cursor));
    }

    public function testHonoursTheLimit(): void
    {
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');

        self::assertSame(['newer'], $this->search('angular', null, 1));
    }

    public function testClampsALimitOfZeroToOneRow(): void
    {
        // searchForUser floors the limit at 1 (max(1, ...)) rather than
        // passing an untouched 0 straight to setMaxResults(), which would
        // return no rows at all.
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');

        self::assertSame(['newer'], $this->search('angular', null, 0));
    }

    public function testTreatsAPercentSignAsAPlainCharacter(): void
    {
        $this->entry('literal', 'Inflation hits 100% this year');
        $this->entry('wildcard', 'Nothing to do with numbers');

        self::assertSame(['literal'], $this->search('100%'));
    }

    public function testTreatsAnUnderscoreAsAPlainCharacter(): void
    {
        $this->entry('literal', 'The snake_case debate');
        $this->entry('wildcard', 'The snakeXcase debate');

        self::assertSame(['literal'], $this->search('snake_case'));
    }

    public function testATrailingSpaceMatchesTheWholeWordOnItsOwn(): void
    {
        $this->entry('plain', 'punk');

        self::assertSame(['plain'], $this->search('punk '));
    }

    public function testATrailingSpaceMatchesTheWordAtTheStartOfTheTitle(): void
    {
        $this->entry('leading', 'Punk rock is back');

        self::assertSame(['leading'], $this->search('punk '));
    }

    public function testATrailingSpaceMatchesTheWordFollowedByAComma(): void
    {
        $this->entry('comma', 'punk, and proud of it');

        self::assertSame(['comma'], $this->search('punk '));
    }

    public function testATrailingSpaceMatchesTheWordInsideParentheses(): void
    {
        $this->entry('parens', 'A genre (punk) explained');

        self::assertSame(['parens'], $this->search('punk '));
    }

    public function testATrailingSpaceDoesNotMatchAWordItOnlyStarts(): void
    {
        $this->entry('miss', 'Es hat gehörig gepunktet');

        self::assertSame([], $this->search('punk '));
    }

    public function testATrailingSpaceDoesNotMatchAWordItOnlyEnds(): void
    {
        $this->entry('miss', 'The rise of cyberpunk');

        self::assertSame([], $this->search('punk '));
    }

    public function testWithoutATrailingSpaceTheOldSubstringBehaviourStillMatches(): void
    {
        $this->entry('hit', 'Es hat gehörig gepunktet');

        self::assertSame(['hit'], $this->search('punk'));
    }

    public function testAMultiTermWholeWordQueryRequiresEveryTermToMatchWhole(): void
    {
        $this->entry('both', 'Die neue Studie zeigt es');
        $this->entry('partial', 'Die neuestudie zeigt es');

        self::assertSame(['both'], $this->search('die neue studie '));
    }

    public function testAMultiTermWholeWordQueryChecksEachTermsOwnWordNotJustAnyTerms(): void
    {
        // "punk" is only a substring here ("cyberpunk"), not a whole word, even
        // though "perfect" is. Each term's whole-word check is bound to its own
        // parameter; if the terms shared one bound value (e.g. all falling back
        // to the last term's pattern), this would wrongly match on "perfect"
        // alone without ever verifying "punk" as its own whole word.
        $this->entry('miss', 'cyberpunk is perfect');

        self::assertSame([], $this->search('punk perfect '));
    }

    public function testWholeWordModeStillTreatsAPercentSignAsAPlainCharacter(): void
    {
        $this->entry('literal', 'Inflation hits 100% this year');
        $this->entry('wildcard', 'Nothing to do with numbers');

        self::assertSame(['literal'], $this->search('100% '));
    }
}
