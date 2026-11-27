<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InterestTerm;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\InterestTermStems;
use App\Service\Curation\Recommendation\Stream\InterestTermStore;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermModel;
use App\Service\Curation\Recommendation\Stream\StreamCeiling;
use App\Tests\DbTestCase;
use App\Tests\Support\StemmingWiring;
use Symfony\Component\Clock\MockClock;

final class InterestTermStoreTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private InterestTermStore $store;
    private InterestTermSetRepository $termSets;
    private InterestTermRepository $storedTerms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('terms@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();

        $clock = new MockClock('2026-11-27T10:00:00');
        $this->termSets = self::getContainer()->get(InterestTermSetRepository::class);
        $this->storedTerms = self::getContainer()->get(InterestTermRepository::class);
        $entries = self::getContainer()->get(EntryMembershipSweepRepository::class);
        $this->store = new InterestTermStore(
            $this->entityManager,
            $this->termSets,
            $this->storedTerms,
            new InterestTermStems(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            new StreamCeiling($entries, $clock),
            $clock,
        );
    }

    public function testTheFirstTermSetStartsTheMarkAtTheSettledCeiling(): void
    {
        $old = $this->entry('old', '2026-11-27T09:00:00');
        $this->entry('young', '2026-11-27T09:59:30');

        $set = $this->store->replaceTerms(
            $this->user,
            new \DateTimeImmutable('2026-11-27T08:00:00'),
            [$this->term('solar')],
        );

        self::assertSame($old->requireId(), $set->matchedUpToEntryId());
        self::assertSame('2026-11-27T10:00:00', $set->getStoredAt()->format('Y-m-d\TH:i:s'));
        self::assertNull($set->getRaritiesRefreshedAt());
    }

    public function testReplacingTheTermsKeepsTheMarkAndClearsTheRarities(): void
    {
        $this->entry('old', '2026-11-27T09:00:00');
        $generatedAt = new \DateTimeImmutable('2026-11-27T08:00:00');
        $set = $this->store->replaceTerms($this->user, $generatedAt, [$this->term('solar')]);
        $this->termSets->advanceMarks([$this->user->requireId()], 42);
        $set->recordRaritiesRefreshed(new \DateTimeImmutable('2026-11-27T09:30:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $user = $this->entityManager->find(User::class, $this->user->requireId());
        self::assertNotNull($user);
        $second = $this->store->replaceTerms($user, $generatedAt, [$this->term('wind')]);

        self::assertSame(42, $second->matchedUpToEntryId());
        self::assertNull($second->getRaritiesRefreshedAt());
        self::assertEqualsCanonicalizing(
            [StemmingWiring::stemmer()->stem('wind', FeedLanguage::English), 'wind'],
            array_map(
                static fn (InterestTerm $term): string => $term->getStem(),
                $this->storedTerms->findForUser($user->requireId()),
            ),
        );
    }

    public function testAnotherUsersTermsAreUntouched(): void
    {
        $other = new User('other-terms@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $generatedAt = new \DateTimeImmutable('2026-11-27T08:00:00');
        $this->store->replaceTerms($other, $generatedAt, [$this->term('solar')]);

        $this->store->replaceTerms($this->user, $generatedAt, [$this->term('wind')]);

        self::assertCount(2, $this->storedTerms->findForUser($other->requireId()));
    }

    private function term(string $phrase): InterestTermModel
    {
        return new InterestTermModel(FeedLanguage::English, $phrase, 0.8);
    }

    private function entry(string $guid, string $createdAt): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
