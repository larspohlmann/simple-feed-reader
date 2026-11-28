<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ReadingHistoryRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Curation\YourStream\InterestArticles;
use App\Service\Curation\YourStream\Model\InterestInputsModel;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryShareFixtures;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\StateMarks;

final class InterestArticlesTest extends DbTestCase
{
    use RequiresServices;

    private User $user;
    private Feed $feed;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('interests@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = $this->subscribedFeed('https://interests.example.com/feed.xml');
    }

    public function testAnArticleFromAFeedOutsideYourStreamIsNotAnInterest(): void
    {
        $switchedOff = $this->subscribedFeed('https://switched-off.example.com/feed.xml');
        StateMarks::markFavorite($this->state($this->entry($switchedOff)));
        $this->subscriptionTo($switchedOff)->changeInclusions(new InclusionChange(yourStream: false));
        $this->entityManager->flush();

        self::assertSame([], $this->collectInputs()->articles);
    }

    public function testViewedArticlesAreNotInterests(): void
    {
        $this->state($this->entry($this->feed))->markViewed(new \DateTimeImmutable('2026-11-01 10:00:00'));
        $favourite = $this->entry($this->feed);
        StateMarks::markFavorite($this->state($favourite));
        $this->entityManager->flush();

        self::assertSame([$favourite->requireId()], $this->articleIds($this->collectInputs()));
    }

    public function testFavouritesKeptSharedAndLikedAreAllInterests(): void
    {
        $favourite = $this->entry($this->feed);
        StateMarks::markFavorite($this->state($favourite));
        $kept = $this->entry($this->feed);
        StateMarks::markKept($this->state($kept));
        $shared = $this->entry($this->feed);
        EntryShareFixtures::share($this->entityManager, $this->user, $shared, new \DateTimeImmutable('2026-11-01'));
        $liked = $this->entry($this->feed);
        StateMarks::markLiked($this->state($liked), new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->entityManager->flush();

        self::assertEqualsCanonicalizing(
            [$favourite->requireId(), $kept->requireId(), $shared->requireId(), $liked->requireId()],
            $this->articleIds($this->collectInputs()),
        );
    }

    public function testEachSectionKeepsItsNewest200(): void
    {
        $start = new \DateTimeImmutable('2026-01-01');
        $oldest = $this->entry($this->feed, $start);
        StateMarks::markKept($this->state($oldest));
        for ($index = 1; $index <= InterestArticles::SECTION_CAP; $index++) {
            StateMarks::markKept($this->state($this->entry($this->feed, $start->modify("+$index hours"))));
        }
        $this->entityManager->flush();

        $articles = $this->articleIds($this->collectInputs());

        self::assertCount(InterestArticles::SECTION_CAP, $articles);
        self::assertNotContains($oldest->requireId(), $articles);
    }

    public function testSavedSearchTermsComeInPositionOrder(): void
    {
        $this->savedSearch('second', 2);
        $this->savedSearch('first', 1);

        self::assertSame(['first', 'second'], $this->collectInputs()->searchTerms);
    }

    public function testAnArticleCarriesItsEntrysWords(): void
    {
        $favourite = $this->entry($this->feed);
        StateMarks::markFavorite($this->state($favourite));
        $this->entityManager->flush();

        self::assertEquals([StreamEntryModel::fromEntry($favourite)], $this->collectInputs()->articles);
    }

    public function testTheFingerprintChangesWithANewFavouriteAndANewSearchButNotWithOrder(): void
    {
        $first = new StreamEntryModel(1, null, 'one', null);
        $second = new StreamEntryModel(2, null, 'two', null);
        self::assertSame(
            (new InterestInputsModel(7, [$first, $second], ['solar', 'wind']))->fingerprint(),
            (new InterestInputsModel(7, [$second, $first], ['wind', 'solar']))->fingerprint(),
        );

        StateMarks::markFavorite($this->state($this->entry($this->feed)));
        $this->savedSearch('solar', 1);
        $this->entityManager->flush();
        $before = $this->collectInputs()->fingerprint();
        StateMarks::markFavorite($this->state($this->entry($this->feed)));
        $this->entityManager->flush();
        $withFavourite = $this->collectInputs()->fingerprint();
        $this->savedSearch('wind', 2);
        $withSearch = $this->collectInputs()->fingerprint();

        self::assertNotSame($before, $withFavourite);
        self::assertNotSame($withFavourite, $withSearch);
    }

    private function collectInputs(): InterestInputsModel
    {
        $interests = new InterestArticles(
            new ReadingHistoryRepository($this->entityManager),
            $this->requireFromContainer(SavedSearchRepository::class),
        );
        $inputs = $interests->collectInputs($this->user->requireId());
        self::assertSame($this->user->requireId(), $inputs->userId);

        return $inputs;
    }

    /** @return list<int> */
    private function articleIds(InterestInputsModel $inputs): array
    {
        return array_map(static fn (StreamEntryModel $article): int => $article->id, $inputs->articles);
    }

    private function subscribedFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01')));
        $this->entityManager->flush();

        return $feed;
    }

    private function subscriptionTo(Feed $feed): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function entry(Feed $feed, ?\DateTimeImmutable $effectiveAt = null): Entry
    {
        $at = $effectiveAt ?? new \DateTimeImmutable('2026-11-01 10:00:00');
        $guid = 'interest-' . $this->nextEntry++;
        $entry = new Entry($feed, $guid, null, 'Title ' . $guid, $at, $at);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function state(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    private function savedSearch(string $term, int $position): void
    {
        $search = new SavedSearch($this->user, $term, false);
        $search->setPosition($position);
        $this->entityManager->persist($search);
        $this->entityManager->flush();
    }
}
