<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestEntryFinder;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Tests\DbTestCase;
use PHPUnit\Framework\MockObject\Stub;

/**
 * DigestComposer turns a user's includeInDigest saved searches into the
 * DigestModel an email renders (#636) — a search with no matches contributes
 * no group, and a user with nothing to report gets no digest at all.
 *
 * DigestEntryFinder now reads the membership table through
 * SavedSearchEntryRepository, which is `final` and cannot be doubled, so
 * these tests run the real finder and hydrator over persisted rows (#1116) —
 * which also exercises DigestComposer against the finder's real capping
 * behaviour (DigestEntryFinder::PER_SEARCH).
 */
final class DigestComposerTest extends DbTestCase
{
    private SavedSearchRepository&Stub $savedSearches;
    private User $user;
    private \DateTimeImmutable $since;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSearches = $this->createStub(SavedSearchRepository::class);

        $this->user = new User('digest@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->since = new \DateTimeImmutable('2026-08-01T00:00:00Z');
    }

    public function testOneMatchingSearchAndOneEmptySearchYieldsOneCappedGroup(): void
    {
        $rust = $this->search('rust');
        $golang = $this->search('golang');
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust, $golang]);

        $newestId = null;
        for ($i = 1; $i <= 12; ++$i) {
            $effectiveDate = new \DateTimeImmutable('2026-08-15T12:00:00Z');
            $entry = $this->member($rust, 'Entry ' . $i, 'Feed ' . $i, $effectiveDate->modify('-' . $i . ' minutes'));
            $newestId ??= $entry->getId();
        }

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
        self::assertStringEndsWith('?entry=' . $newestId, $group->entries[0]->url);
    }

    public function testShortDescriptionStripsTagsAndCapsLength(): void
    {
        $rust = $this->search('rust');
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
        $entry = $this->member($rust, 'Title', 'Feed A', new \DateTimeImmutable('2026-08-15T00:00:00Z'));
        $entry->setSummary('<p>' . str_repeat('word ', 60) . '</p>');
        $this->em->flush();

        $model = $this->composer()->compose($this->user, $this->since);

        self::assertNotNull($model);
        $description = $model->groups[0]->entries[0]->shortDescription;
        self::assertStringNotContainsString('<p>', $description);
        self::assertLessThanOrEqual(201, mb_strlen($description), 'Capped text plus the ellipsis character.');
        self::assertStringEndsWith('…', $description);
    }

    public function testAllSearchesEmptyReturnsNull(): void
    {
        $rust = $this->search('rust');
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);

        self::assertNull($this->composer()->compose($this->user, $this->since));
    }

    public function testUserWithNoIncludedSearchesReturnsNull(): void
    {
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([]);

        self::assertNull($this->composer()->compose($this->user, $this->since));
    }

    public function testEntryCarriesImagePublishedDateAndFavicon(): void
    {
        $rust = $this->search('rust');
        $this->savedSearches->method('findIncludedInDigestForUser')->willReturn([$rust]);
        $entry = $this->member($rust, 'Title', 'Feed A', new \DateTimeImmutable('2026-08-15T00:00:00Z'));
        $entry->setPublishedAt(new \DateTimeImmutable('2026-08-15T09:48:00Z'));
        $entry->getImage()->storePending('https://cdn.example.com/1.jpg', 1200, 900);
        $entry->getFeed()->setFaviconUrl('https://example.com/favicon.ico');
        $this->em->flush();

        $model = $this->composer()->compose($this->user, $this->since);

        self::assertNotNull($model);
        $entryModel = $model->groups[0]->entries[0];
        self::assertSame('https://cdn.example.com/1.jpg', $entryModel->imageUrl);
        self::assertSame('https://example.com/favicon.ico', $entryModel->faviconUrl);
        self::assertEquals(new \DateTimeImmutable('2026-08-15T09:48:00Z'), $entryModel->publishedAt);
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);
        $this->em->flush();

        return $search;
    }

    private function member(
        SavedSearch $search,
        string $title,
        string $feedTitle,
        \DateTimeImmutable $effectiveDate,
    ): Entry {
        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle($feedTitle);
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'guid-' . uniqid('', true),
            'https://example.com/' . uniqid('', true),
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $effectiveDate,
        );
        $this->em->persist($entry);
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')));
        $this->em->flush();

        return $entry;
    }

    private function composer(): DigestComposer
    {
        return new DigestComposer(
            $this->savedSearches,
            new DigestEntryFinder($this->members(), $this->entryListRepository()),
            new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example')),
        );
    }

    private function members(): SavedSearchEntryRepository
    {
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repo);

        return $repo;
    }

    private function entryListRepository(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }
}
