<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\EntryOrigin;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\SavedEntryOrigin;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\ShareEnrichment;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Entity\User;
use App\Enum\CommentsLoad;
use App\Enum\ShareEnrichmentKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowDiscussion;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListRowSocial;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\LinkCardRepository;
use App\Service\LinkCard\Model\PlacedLinkCardModel;
use App\Service\SavedPosts\Support\SavedFeedUrl;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;

final class EntryListRowEnricherTest extends DbTestCase
{
    use SeedsUsers;

    private const string POST = 'https://mastodon.social/@Gargron/';

    private Feed $feed;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager->persist($this->feed);
        $this->user = $this->user('enricher@example.com');
    }

    public function testARowGainsItsCategoriesInDeclaredOrderItsSavedSearchesAndItsLinkCardsInPostOrder(): void
    {
        $entry = $this->entry('full');
        $this->category($entry, 1, 'World');
        $this->category($entry, 0, 'Politics');
        $this->card($entry, 1, 'https://b.test/');
        $this->card($entry, 0, 'https://a.test/');
        $older = $this->savedSearchWithMember('climate', $entry);
        $newer = $this->savedSearchWithMember('rocket', $entry);

        $enrichment = $this->enrich([$this->row($entry)])[0]->enrichment;

        self::assertSame(['Politics', 'World'], $enrichment->categories);
        self::assertSame([$newer->requireId(), $older->requireId()], array_column($enrichment->savedSearches, 'id'));
        self::assertSame(
            ['id' => $newer->requireId(), 'slug' => $newer->getSlug(), 'term' => 'rocket'],
            $enrichment->savedSearches[0],
        );
        self::assertSame(['https://a.test/', 'https://b.test/'], $this->urlsOf($enrichment->linkCards));
    }

    public function testARowGainsItsThreadPostsWithTheirCardsAndKeepsRootCardsApart(): void
    {
        $entry = $this->entry('threaded');
        $this->card($entry, 0, 'https://root.test/');
        $reply = new ThreadPost($entry, 1, new ThreadPostContent('https://x/1', null, '<p>one</p>', []));
        $this->entityManager->persist($reply);
        $this->linkCards()->appendToThreadPost(
            $reply,
            new LinkCardContent('https://reply.test/', 'R', null, null, 'S'),
        );
        $this->entityManager->flush();

        $enrichment = $this->enrich([$this->row($entry)])[0]->enrichment;

        self::assertSame(['https://root.test/'], $this->urlsOf($enrichment->linkCards));
        self::assertCount(1, $enrichment->threadPosts);
        self::assertSame('https://reply.test/', $enrichment->threadPosts[0]->linkCard?->content->url);
        self::assertSame(1, $enrichment->threadPosts[0]->linkCard->position);
    }

    private function linkCards(): LinkCardRepository
    {
        $repository = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $repository);

        return $repository;
    }

    /**
     * @param list<PlacedLinkCardModel> $cards
     *
     * @return list<string>
     */
    private function urlsOf(array $cards): array
    {
        return array_map(static fn (PlacedLinkCardModel $card): string => $card->content->url, $cards);
    }

    public function testEachRowAndEachDuplicateGainsOnlyItsOwnEnrichment(): void
    {
        $survivor = $this->entry('survivor');
        $duplicate = $this->entry('duplicate');
        $bare = $this->entry('bare');
        $this->category($survivor, 0, 'Politics');
        $this->category($duplicate, 0, 'Tech');
        $this->card($duplicate, 0, 'https://dup.test/');
        $search = $this->savedSearchWithMember('climate', $duplicate);

        $rows = $this->enrich([$this->row($survivor, [$this->row($duplicate)]), $this->row($bare)]);

        self::assertSame(['Politics'], $rows[0]->enrichment->categories);
        self::assertSame([], $rows[0]->enrichment->savedSearches);
        self::assertSame([], $rows[0]->enrichment->linkCards);
        $duplicateEnrichment = $rows[0]->duplicates[0]->enrichment;
        self::assertSame(['Tech'], $duplicateEnrichment->categories);
        self::assertSame([$search->requireId()], array_column($duplicateEnrichment->savedSearches, 'id'));
        self::assertSame(['https://dup.test/'], $this->urlsOf($duplicateEnrichment->linkCards));
        self::assertEquals([[], [], []], [
            $rows[1]->enrichment->categories,
            $rows[1]->enrichment->savedSearches,
            $rows[1]->enrichment->linkCards,
        ]);
    }

    public function testAnotherUsersSavedSearchIsNotShown(): void
    {
        $entry = $this->entry('foreign');
        $this->savedSearchWithMember('climate', $entry);

        $rows = $this->enricher()->enrich([$this->row($entry)], $this->user('stranger@example.com')->requireId());

        self::assertSame([], $rows[0]->enrichment->savedSearches);
    }

    public function testAPostIsPendingWhileItsEnrichmentIsNotDone(): void
    {
        $blueskyPost = $this->queued('bluesky', ShareEnrichmentKind::AppView);
        $linkPost = $this->queued('link', ShareEnrichmentKind::LinkCards);
        $done = $this->queued('done', ShareEnrichmentKind::LinkCards);
        $unqueued = $this->entry('unqueued');
        $this->entityManager->flush();
        $this->markDone($done);

        $rows = $this->enrich(array_map($this->row(...), [$blueskyPost, $linkPost, $done, $unqueued]));

        self::assertSame(
            [true, true, false, false],
            array_map(static fn (EntryListRow $row): bool => $row->enrichment->pending, $rows),
        );
    }

    public function testOnlyATopLevelRowIsPendingNeverADuplicate(): void
    {
        $survivor = $this->queued('survivor', ShareEnrichmentKind::LinkCards);
        $duplicate = $this->queued('duplicate', ShareEnrichmentKind::AppView);
        $settled = $this->entry('settled');
        $pendingDuplicate = $this->queued('pending-duplicate', ShareEnrichmentKind::AppView);

        $rows = $this->enrich([
            $this->row($survivor, [$this->row($duplicate)]),
            $this->row($settled, [$this->row($pendingDuplicate)]),
        ]);

        self::assertTrue($rows[0]->enrichment->pending);
        self::assertFalse($rows[0]->duplicates[0]->enrichment->pending);
        self::assertFalse($rows[1]->enrichment->pending);
        self::assertFalse($rows[1]->duplicates[0]->enrichment->pending);
    }

    public function testASavedArticleGainsItsOriginAndAnyOtherEntryGainsNone(): void
    {
        $saved = $this->savedEntry('saved');
        $plain = $this->entry('plain-origin');
        $this->entityManager->persist(new SavedEntryOrigin($saved, new EntryOrigin('Heise', 'https://h.test/f.ico')));
        $this->entityManager->flush();

        $rows = $this->enrich([$this->row($saved), $this->row($plain)]);

        self::assertEquals(new EntryOrigin('Heise', 'https://h.test/f.ico'), $rows[0]->enrichment->origin);
        self::assertNull($rows[1]->enrichment->origin);
    }

    public function testAPageCostsOneQueryPerLookup(): void
    {
        $first = $this->queued('first', ShareEnrichmentKind::LinkCards);
        $second = $this->savedEntry('second');
        $duplicate = $this->entry('second-duplicate');
        $this->category($first, 0, 'Tech');
        $this->category($duplicate, 0, 'Science');
        $this->card($second, 0, 'https://a.test/');
        $this->savedSearchWithMember('climate', $first);
        $recorder = $this->recorder();

        $this->enrich([$this->row($first), $this->row($second, [$this->row($duplicate)])]);

        $queries = implode("\n", $recorder->queries());
        self::assertCount(6, $recorder->queries(), $queries);
        $tables = [
            'entry_category',
            'saved_search_entry',
            'link_card',
            'FROM thread_post',
            'share_enrichment',
            'saved_entry_origin',
        ];
        foreach ($tables as $table) {
            self::assertCount(1, $recorder->queriesMatching($table), $table . ":\n" . $queries);
        }
    }

    public function testAPageWithoutASavedArticleLooksUpNoOrigins(): void
    {
        $recorder = $this->recorder();

        $this->enrich([$this->row($this->entry('unsaved'))]);

        self::assertSame([], $recorder->queriesMatching('saved_entry_origin'));
    }

    public function testAPostOnANetworkTheUserHasAnAccountOnCarriesItsSocialStateAndLike(): void
    {
        $account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $mastodon = $this->mastodonFeed();
        $liked = $this->postOn($mastodon, self::POST . '1');
        $unliked = $this->postOn($mastodon, self::POST . '2');
        $article = $this->entry('article');
        $this->likeOf($account, $this->user, $liked);

        $rows = $this->enrich([$this->row($liked, [$this->row($unliked)]), $this->row($article)]);

        self::assertEquals(new EntryListRowSocial(SocialNetwork::Mastodon, true, true), $rows[0]->enrichment->social);
        self::assertEquals(
            new EntryListRowSocial(SocialNetwork::Mastodon, false, true),
            $rows[0]->duplicates[0]->enrichment->social,
        );
        self::assertNull($rows[1]->enrichment->social);
    }

    public function testAPostsRepliesAreItsDiscussionAndAnArticleKeepsItsOwn(): void
    {
        $post = $this->postOn($this->mastodonFeed(), self::POST . '4');
        $article = $this->entry('article');

        $rows = $this->enrich([$this->row($post), $this->row($article)]);

        self::assertEquals(
            new EntryListRowDiscussion(self::POST . '4', CommentsLoad::Auto),
            $rows[0]->enrichment->discussion,
        );
        self::assertNull($rows[1]->enrichment->discussion);
    }

    public function testAnotherUsersLikeNeverShowsAsLiked(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $stranger = $this->user('social-stranger@example.com');
        $strangerAccount = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($stranger));
        $post = $this->postOn($this->mastodonFeed(), self::POST . '5');
        $this->likeOf($strangerAccount, $stranger, $post);

        $social = $this->enrich([$this->row($post)])[0]->enrichment->social;

        self::assertEquals(new EntryListRowSocial(SocialNetwork::Mastodon, false, true), $social);
    }

    public function testWithoutAnAccountOnItsNetworkAPostCannotBeLiked(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $post = $this->postOn($this->mastodonFeed(), self::POST . '3');

        self::assertEquals(
            new EntryListRowSocial(SocialNetwork::Mastodon, false, false),
            $this->enrich([$this->row($post)])[0]->enrichment->social,
        );
    }

    public function testTheLikeLookupIsOneQueryHoweverManyPostsThePageHolds(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $feed = $this->mastodonFeed();
        $single = [$this->row($this->postOn($feed, self::POST . '4'))];
        $many = array_map(
            fn (int $number): EntryListRow => $this->row($this->postOn($feed, self::POST . 'many' . $number)),
            range(1, 5),
        );

        $recorder = $this->recorder();
        $this->enrich($single);
        $singleCount = \count($recorder->queries());
        $recorder = $this->recorder();
        $this->enrich($many);

        self::assertSame(7, $singleCount);
        self::assertCount($singleCount, $recorder->queries(), implode("\n", $recorder->queries()));
        self::assertCount(1, $recorder->queriesMatching('social_like'));
    }

    public function testAPageOfPlainArticlesLooksUpNoSocialAccounts(): void
    {
        $recorder = $this->recorder();

        $this->enrich([$this->row($this->entry('plain-one')), $this->row($this->entry('plain-two'))]);

        self::assertSame([], $recorder->queriesMatching('social_account'));
        self::assertSame([], $recorder->queriesMatching('social_like'));
    }

    public function testAPageWithAPostLooksUpTheSocialAccountsOnce(): void
    {
        $post = $this->postOn($this->mastodonFeed(), self::POST . '6');
        $recorder = $this->recorder();

        $this->enrich([$this->row($post), $this->row($this->entry('plain-three'))]);

        self::assertCount(1, $recorder->queriesMatching('social_account'));
        self::assertSame([], $recorder->queriesMatching('social_like'));
    }

    public function testNoRowsStayNoRowsWithoutAQuery(): void
    {
        $recorder = $this->recorder();

        self::assertSame([], $this->enrich([]));

        self::assertSame([], $recorder->queries());
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    private function enrich(array $rows): array
    {
        return $this->enricher()->enrich($rows, $this->user->requireId());
    }

    private function enricher(): EntryListRowEnricher
    {
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);

        return $enricher;
    }

    private function recorder(): QueryRecorder
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        return $recorder;
    }

    private function entry(string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-07-02T00:00:00Z');
        $entry = new Entry($this->feed, $guid, 'https://x.test/' . $guid, $guid, $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function savedEntry(string $guid): Entry
    {
        $feed = new Feed(SavedFeedUrl::of($this->user->requireId(), null));
        $feed->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($feed);

        return $this->postOn($feed, 'https://x.test/' . $guid);
    }

    private function mastodonFeed(): Feed
    {
        $feed = new Feed('https://mastodon.social/@Gargron.rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function postOn(Feed $feed, string $url): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13T00:00:00Z');
        $entry = new Entry($feed, $url, $url, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function likeOf(SocialAccount $account, User $user, Entry $entry): void
    {
        $this->entityManager->persist(
            new SocialLike($user, $entry, $account, '1', new \DateTimeImmutable('2026-10-13 10:00:00')),
        );
        $this->entityManager->flush();
    }

    private function category(Entry $entry, int $position, string $label): void
    {
        $category = new Category(strtolower($label), '');
        $this->entityManager->persist($category);
        $this->entityManager->persist(new EntryCategory($entry, $category, $position, $label));
        $this->entityManager->flush();
    }

    private function card(Entry $entry, int $position, string $url): void
    {
        $this->entityManager->persist(new LinkCard($entry, $position, new LinkCardContent($url, 'T', null, null, 'S')));
        $this->entityManager->flush();
    }

    private function savedSearchWithMember(string $term, Entry $entry): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();
        $search->setSlug($search->requireId() . '-' . $term);
        $this->entityManager->persist(
            new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')),
        );
        $this->entityManager->flush();

        return $search;
    }

    private function queued(string $guid, ShareEnrichmentKind $kind): Entry
    {
        $entry = $this->entry($guid);
        $this->entityManager->persist(new ShareEnrichment($entry, $kind, new \DateTimeImmutable('2026-10-10')));
        $this->entityManager->flush();

        return $entry;
    }

    private function markDone(Entry $entry): void
    {
        $enrichments = PostEnrichers::enrichments($this->entityManager);
        $claimedAt = new \DateTimeImmutable('2026-10-10');
        $enrichments->markDone($enrichments->claim($enrichments->findBy(['entry' => $entry]), $claimedAt), $claimedAt);
    }

    /** @param list<EntryListRow> $duplicates */
    private function row(Entry $entry, array $duplicates = []): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
            $duplicates,
        );
    }
}
