<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntrySearchQuery;
use App\Repository\SavedSearchRepository;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * DigestComposer turns a user's includeInDigest saved searches into the
 * DigestModel an email renders (#636) — a search with no matches contributes
 * no group, and a user with nothing to report gets no digest at all.
 *
 * DigestEntryFinder is `final readonly`, so it cannot be doubled: these tests
 * run the real finder against a mocked EntryListRepository instead, exactly
 * as DigestEntryFinderTest does. That also exercises DigestComposer against
 * the finder's real capping behaviour (DigestEntryFinder::PER_SEARCH).
 */
final class DigestComposerTest extends TestCase
{
    private SavedSearchRepository&Stub $savedSearches;
    private EntryListRepository&Stub $entries;
    private User $user;
    private \DateTimeImmutable $since;

    protected function setUp(): void
    {
        $this->savedSearches = $this->createStub(SavedSearchRepository::class);
        $this->entries = $this->createStub(EntryListRepository::class);

        $this->user = new User('digest@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, 42);

        $this->since = new \DateTimeImmutable('2026-08-01T00:00:00Z');
    }

    public function testOneMatchingSearchAndOneEmptySearchYieldsOneCappedGroup(): void
    {
        $rust = new SavedSearch($this->user, 'rust', false);
        $golang = new SavedSearch($this->user, 'golang', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust, $golang]);

        $ids = range(1, 12);
        $rows = array_map(fn (int $id): EntryListRow => $this->row($id, 'Entry ' . $id, 'Feed ' . $id), $ids);

        $this->entries->method('unreadMatchIdsSince')->willReturnCallback(
            fn (EntrySearchQuery $query): array => $query->terms->terms === ['rust'] ? $ids : [],
        );
        $this->entries->method('rowsByIdsForUser')->willReturn($rows);

        $model = $this->composer()->compose($this->user, $this->since);

        self::assertNotNull($model);
        self::assertSame(12, $model->totalCount);
        self::assertCount(1, $model->groups, 'The empty "golang" search must not contribute a group.');

        $group = $model->groups[0];
        self::assertSame('rust', $group->term);
        self::assertSame(12, $group->totalCount);
        self::assertTrue($group->hasMore);
        self::assertCount(DigestEntryFinder::PER_SEARCH, $group->entries);
        self::assertStringEndsWith('?q=rust', $group->moreUrl);

        self::assertSame('Entry 1', $group->entries[0]->title);
        self::assertSame('Feed 1', $group->entries[0]->feedName);
        self::assertStringEndsWith('?entry=1', $group->entries[0]->url);
    }

    public function testShortDescriptionStripsTagsAndCapsLength(): void
    {
        $rust = new SavedSearch($this->user, 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);

        $row = $this->row(1, 'Title', 'Feed A');
        $row->entry->setSummary('<p>' . str_repeat('word ', 60) . '</p>');

        $this->entries->method('unreadMatchIdsSince')->willReturn([1]);
        $this->entries->method('rowsByIdsForUser')->willReturn([$row]);

        $model = $this->composer()->compose($this->user, $this->since);

        self::assertNotNull($model);
        $description = $model->groups[0]->entries[0]->shortDescription;
        self::assertStringNotContainsString('<p>', $description);
        self::assertLessThanOrEqual(201, mb_strlen($description), 'Capped text plus the ellipsis character.');
        self::assertStringEndsWith('…', $description);
    }

    public function testAllSearchesEmptyReturnsNull(): void
    {
        $rust = new SavedSearch($this->user, 'rust', false);
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
        $this->entries->method('unreadMatchIdsSince')->willReturn([]);

        self::assertNull($this->composer()->compose($this->user, $this->since));
    }

    public function testUserWithNoIncludedSearchesReturnsNull(): void
    {
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([]);
        $entries = $this->createMock(EntryListRepository::class);
        $entries->expects(self::never())->method('unreadMatchIdsSince');

        $composer = new DigestComposer(
            $this->savedSearches,
            new DigestEntryFinder($entries),
            new DigestLinkBuilder('https://reader.example'),
        );

        self::assertNull($composer->compose($this->user, $this->since));
    }

    private function composer(): DigestComposer
    {
        return new DigestComposer(
            $this->savedSearches,
            new DigestEntryFinder($this->entries),
            new DigestLinkBuilder('https://reader.example'),
        );
    }

    private function row(int $id, string $title, string $feedName): EntryListRow
    {
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid-' . $id,
            'https://example.com/' . $id,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        // Entry has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        new \ReflectionProperty(Entry::class, 'id')->setValue($entry, $id);

        return new EntryListRow(
            entry: $entry,
            subscriptionId: 1,
            subscriptionTitle: $feedName,
            isHidden: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            viewedAt: null,
            markedReadUntil: null,
        );
    }
}
