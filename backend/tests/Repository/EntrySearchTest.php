<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\ListOrder;
use App\Pagination\EntryCursor;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;

/**
 * The LIKE search over title and summary, with ASCII terms only: MySQL's collation folds case and accents, SQLite's
 * LIKE folds ASCII case only, so "Ubung" finding "Übung" would pass in production and fail here.
 */
final class EntrySearchTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);

        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $this->entityManager->flush();
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
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function repository(): EntryListRepository
    {
        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);

        return $repository;
    }

    /** @return list<string> the guids a search query returned, in order */
    private function guidsOf(EntrySearchQuery $query): array
    {
        $rows = $this->repository()->searchForUser($query);

        return array_map(static fn ($row) => $row->entry->getGuid(), $rows);
    }

    /** @return list<string> the guids the search returned, in order */
    private function search(string $input, ?EntryCursor $cursor = null, int $limit = 50): array
    {
        return $this->guidsOf(new EntrySearchQuery(
            userId: $this->user->requireId(),
            terms: SearchTermsModel::fromInput($input),
            cursor: $cursor,
            limit: $limit,
        ));
    }

    /** @return list<string> the unread guids the search returned, in order */
    private function unreadSearch(string $input, ?EntryCursor $cursor = null, int $limit = 50): array
    {
        return $this->guidsOf(new EntrySearchQuery(
            userId: $this->user->requireId(),
            terms: SearchTermsModel::fromInput($input),
            cursor: $cursor,
            limit: $limit,
            unread: true,
        ));
    }

    /** @return list<string> the guids an oldest-first search returned, in order */
    private function oldestFirstSearch(string $input, ?EntryCursor $cursor = null): array
    {
        return $this->guidsOf(new EntrySearchQuery(
            userId: $this->user->requireId(),
            terms: SearchTermsModel::fromInput($input),
            cursor: $cursor,
            order: ListOrder::OldestFirst,
        ));
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
        // Neither entry carries both terms, so nothing matches. Terms sharing one bound parameter would all take the
        // last term's value, and the entry matching only that term would come back.
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
        $this->entityManager->persist($other);
        $foreign = new Entry(
            $other,
            'foreign',
            'https://other.example.com/foreign',
            'Angular elsewhere',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->entityManager->persist($foreign);
        $this->entityManager->flush();

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

        $cursor = new EntryCursor($newer->getEffectiveDate(), $newer->requireId());

        self::assertSame(['older'], $this->search('angular', $cursor));
    }

    public function testReturnsOldestFirstWhenAsked(): void
    {
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');
        $this->entry('older-lower-id', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('older-higher-id', 'Angular one', null, '2026-07-10T00:00:00Z');

        self::assertSame(
            ['older-lower-id', 'older-higher-id', 'newer'],
            $this->oldestFirstSearch('angular'),
        );
    }

    public function testPagesOldestFirstWithTheKeysetCursor(): void
    {
        $older = $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('older-tied-a', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('older-tied-b', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');

        $cursor = new EntryCursor($older->getEffectiveDate(), $older->requireId());

        self::assertSame(
            ['older-tied-a', 'older-tied-b', 'newer'],
            $this->oldestFirstSearch('angular', $cursor),
        );
    }

    public function testUnreadSearchAppliesKeysetPaginationAfterFilteringReadMatches(): void
    {
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $middle = $this->entry('middle', 'Angular two', null, '2026-07-11T00:00:00Z');
        $newer = $this->entry('newer', 'Angular three', null, '2026-07-12T00:00:00Z');
        $read = new EntryState($this->user, $newer);
        $read->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($read);
        $this->entityManager->flush();

        self::assertSame(['middle'], $this->unreadSearch('angular', limit: 1));
        $cursor = new EntryCursor($middle->getEffectiveDate(), $middle->requireId());
        self::assertSame(['older'], $this->unreadSearch('angular', $cursor, 1));
    }

    public function testHonoursTheLimit(): void
    {
        $this->entry('older', 'Angular one', null, '2026-07-10T00:00:00Z');
        $this->entry('newer', 'Angular two', null, '2026-07-12T00:00:00Z');

        self::assertSame(['newer'], $this->search('angular', null, 1));
    }

    public function testClampsALimitOfZeroToOneRow(): void
    {
        // EntrySearchQuery clamps 0 to 1; an untouched 0 would reach setMaxResults() and return no rows.
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

    // A whole-word match turns the haystack's punctuation into spaces, so the term must be normalized the same way;
    // German prose makes a hyphenated term the common case.
    public function testATrailingSpaceMatchesAHyphenatedTerm(): void
    {
        $this->entry('hyphen', 'Die neue E-Mail-Adresse ist da');

        self::assertSame(['hyphen'], $this->search('E-Mail '));
    }

    public function testATrailingSpaceMatchesATermCarryingASlash(): void
    {
        $this->entry('slash', 'The TCP/IP stack explained');

        self::assertSame(['slash'], $this->search('TCP/IP '));
    }

    public function testAHyphenatedTermMatchesTheSameWordWrittenWithAnEnDash(): void
    {
        // Both normalize to "E Mail", so both match. It is also why a punctuated term skips the cheap "%term%"
        // prefilter: "E-Mail" is not a raw substring of "E–Mail".
        $this->entry('endash', 'Die neue E–Mail kam an');

        self::assertSame(['endash'], $this->search('E-Mail '));
    }

    public function testAHyphenatedTermStillDoesNotMatchAWordItOnlyStarts(): void
    {
        // Normalizing the term must not have widened whole-word mode back into
        // substring mode: "E-Mail" normalizes to "E Mail", which must not be
        // found inside "E-Mailadresse" ("E Mailadresse").
        $this->entry('miss', 'Die E-Mailadresse fehlt');

        self::assertSame([], $this->search('E-Mail '));
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
        // "punk" is only a substring here ("cyberpunk"); "perfect" is a whole word. Each term's check binds its own
        // parameter, or this would match on "perfect" alone.
        $this->entry('miss', 'cyberpunk is perfect');

        self::assertSame([], $this->search('punk perfect '));
    }

    public function testWholeWordModeStillTreatsAPercentSignAsAPlainCharacter(): void
    {
        $this->entry('literal', 'Inflation hits 100% this year');
        $this->entry('wildcard', 'Nothing to do with numbers');

        self::assertSame(['literal'], $this->search('100% '));
    }

    public function testAQuotedPhraseMatchesOnlyTheWordsTogetherInOrder(): void
    {
        $this->entry('together', 'The climate change report is out');
        $this->entry('apart', 'A change in the climate of the debate');

        self::assertSame(['together'], $this->search('"climate change"'));
    }

    public function testTheSameWordsUnquotedMatchThemApart(): void
    {
        // The contrast to the phrase search above: without the quotes the two
        // words match wherever each appears, so the entry that carries them
        // apart qualifies too.
        $this->entry('together', 'The climate change report is out');
        $this->entry('apart', 'A change in the climate of the debate');

        self::assertSame(['apart', 'together'], $this->search('climate change'));
    }

    public function testAQuotedPhraseStillMatchesAcrossASingleSpace(): void
    {
        $this->entry('hit', 'Untitled', 'a deep dive into machine learning today');

        self::assertSame(['hit'], $this->search('"machine learning"'));
    }
}
