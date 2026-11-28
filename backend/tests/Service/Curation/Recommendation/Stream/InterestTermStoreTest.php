<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InterestPhrase;
use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\InterestTermStems;
use App\Service\Curation\Recommendation\Stream\InterestTermStore;
use App\Service\Curation\Recommendation\Stream\Model\ExtractedTermsModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use App\Tests\DbTestCase;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
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
            new SettledCeiling($entries, $clock),
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
            $this->buildExtractedTerms('solar'),
        );

        self::assertSame($old->requireId(), $set->matchedUpToEntryId());
        self::assertSame('2026-11-27T10:00:00', $set->getStoredAt()->format('Y-m-d\TH:i:s'));
        self::assertNull($set->getRaritiesRefreshedAt());
    }

    public function testReplacingTheTermsKeepsTheMarkAndClearsTheRarities(): void
    {
        $this->entry('old', '2026-11-27T09:00:00');
        $generatedAt = new \DateTimeImmutable('2026-11-27T08:00:00');
        $set = $this->store->replaceTerms($this->user, $generatedAt, $this->buildExtractedTerms('solar'));
        $advancedMark = $set->matchedUpToEntryId() + 42;
        $this->termSets->advanceMarks([$this->user->requireId()], $advancedMark);
        $set->recordRaritiesRefreshed(new \DateTimeImmutable('2026-11-27T09:30:00'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $user = $this->entityManager->find(User::class, $this->user->requireId());
        self::assertNotNull($user);
        $second = $this->store->replaceTerms($user, $generatedAt, $this->buildExtractedTerms('wind'));

        self::assertSame($advancedMark, $second->matchedUpToEntryId());
        self::assertNull($second->getRaritiesRefreshedAt());
        self::assertEqualsCanonicalizing(
            [StemmingWiring::stemmer()->stem('wind', FeedLanguage::English), 'wind'],
            array_map(
                static fn (InterestTerm $term): string => $term->getStem(),
                $this->storedTerms->findForUser($user->requireId()),
            ),
        );
    }

    public function testAnOlderSweepCannotMoveTheMarkBackwards(): void
    {
        $this->store->replaceTerms(
            $this->user,
            new \DateTimeImmutable('2026-11-27T08:00:00'),
            $this->buildExtractedTerms('solar'),
        );
        $this->termSets->advanceMarks([$this->user->requireId()], 500);

        $this->termSets->advanceMarks([$this->user->requireId()], 120);
        $this->entityManager->clear();

        $set = $this->termSets->findForUser($this->requireUser());
        self::assertSame(500, $set?->matchedUpToEntryId());
    }

    public function testAnotherUsersTermsAreUntouched(): void
    {
        $other = new User('other-terms@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $generatedAt = new \DateTimeImmutable('2026-11-27T08:00:00');
        $this->store->replaceTerms($other, $generatedAt, $this->buildExtractedTerms('solar'));

        $this->store->replaceTerms($this->user, $generatedAt, $this->buildExtractedTerms('wind'));

        self::assertCount(2, $this->storedTerms->findForUser($other->requireId()));
    }

    public function testReplacingTermsStoresThePhrasesWithTheStems(): void
    {
        $phrases = [
            new InterestPhrase(FeedLanguage::English, 'renewable energy', 0.9),
            new InterestPhrase(FeedLanguage::German, 'Wärmepumpe', 0.6),
        ];

        $this->store->replaceTerms(
            $this->user,
            new \DateTimeImmutable('2026-11-27T08:00:00'),
            $this->buildExtractedTermsFrom($phrases),
        );
        $this->entityManager->clear();

        $stored = $this->termSets->findForUser($this->requireUser());
        self::assertEquals($phrases, $stored?->getPhrases());
        self::assertNotEmpty($this->storedTerms->findForUser($this->user->requireId()));
    }

    public function testASecondReplaceSwapsThePhrasesWholesale(): void
    {
        $generatedAt = new \DateTimeImmutable('2026-11-27T08:00:00');
        $this->store->replaceTerms($this->user, $generatedAt, $this->buildExtractedTermsFrom([
            new InterestPhrase(FeedLanguage::English, 'alpha', 1.0),
            new InterestPhrase(FeedLanguage::English, 'beta', 0.5),
        ]));
        $this->entityManager->clear();

        $this->store->replaceTerms($this->requireUser(), $generatedAt, $this->buildExtractedTermsFrom([
            new InterestPhrase(FeedLanguage::French, 'vélo', 0.7),
        ]));
        $this->entityManager->clear();

        $stored = $this->termSets->findForUser($this->requireUser());
        self::assertEquals([new InterestPhrase(FeedLanguage::French, 'vélo', 0.7)], $stored?->getPhrases());
    }

    public function testATermSetWithoutStoredPhrasesReadsAsEmpty(): void
    {
        $this->entityManager->persist(new InterestTermSet(
            $this->user,
            new \DateTimeImmutable('2026-11-27T08:00:00'),
            new \DateTimeImmutable('2026-11-27T10:00:00'),
            0,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->termSets->findForUser($this->requireUser());
        self::assertSame([], $stored?->getPhrases());
    }

    public function testAStoredPhraseMissingAKeyFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InterestPhrase::fromStoredArray(['language' => 'en', 'phrase' => 'solar']);
    }

    /** @param array<mixed> $stored */
    #[DataProvider('provideMalformedStoredPhrases')]
    public function testAMalformedStoredPhraseFailsLoudly(array $stored): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InterestPhrase::fromStoredArray($stored);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function provideMalformedStoredPhrases(): iterable
    {
        yield 'a language that is no string' => [['language' => 7, 'phrase' => 'solar', 'weight' => 0.4]];
        yield 'a phrase that is no string' => [['language' => 'en', 'phrase' => 12, 'weight' => 0.4]];
        yield 'a weight that is no number' => [['language' => 'en', 'phrase' => 'solar', 'weight' => 'heavy']];
    }

    public function testAnIntegerWeightInStorageComesBackAsFloat(): void
    {
        $phrase = InterestPhrase::fromStoredArray(['language' => 'en', 'phrase' => 'solar', 'weight' => 3]);

        self::assertSame(3.0, $phrase->weight);
    }

    public function testANumericStringWeightInStorageComesBackAsFloat(): void
    {
        $phrase = InterestPhrase::fromStoredArray(['language' => 'en', 'phrase' => 'solar', 'weight' => '0.35']);

        self::assertSame(0.35, $phrase->weight);
    }

    private function requireUser(): User
    {
        $user = $this->entityManager->find(User::class, $this->user->requireId());
        self::assertNotNull($user);

        return $user;
    }

    private function buildExtractedTerms(string $phrase): ExtractedTermsModel
    {
        return $this->buildExtractedTermsFrom([new InterestPhrase(FeedLanguage::English, $phrase, 0.8)]);
    }

    /** @param list<InterestPhrase> $phrases */
    private function buildExtractedTermsFrom(array $phrases): ExtractedTermsModel
    {
        $stems = (new InterestTermStems(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()))
            ->buildTermStems($phrases);

        return new ExtractedTermsModel($phrases, $stems);
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
