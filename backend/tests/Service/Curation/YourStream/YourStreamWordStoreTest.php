<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\YourStreamStateRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\YourStreamWordStore;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\SettledCeiling;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class YourStreamWordStoreTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private MockClock $clock;
    private YourStreamWordStore $store;
    private YourStreamStateRepository $states;
    private YourStreamWordRepository $words;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('words@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();

        $this->clock = new MockClock('2026-11-28 10:00:00');
        $states = self::getContainer()->get(YourStreamStateRepository::class);
        $words = self::getContainer()->get(YourStreamWordRepository::class);
        $entries = self::getContainer()->get(EntryMembershipSweepRepository::class);
        self::assertInstanceOf(YourStreamStateRepository::class, $states);
        self::assertInstanceOf(YourStreamWordRepository::class, $words);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $entries);
        $this->states = $states;
        $this->words = $words;
        $this->store = new YourStreamWordStore(
            $this->entityManager,
            $states,
            $words,
            new SettledCeiling($entries, $this->clock),
            new NaiveUtcClock($this->clock),
        );
    }

    public function testTheFirstStateStartsTheMarkAtTheSettledCeiling(): void
    {
        $settled = $this->entry('2026-11-28 09:00:00');
        $this->entry('2026-11-28 09:59:30');

        $state = $this->store->ensureState($this->user);
        $this->entityManager->clear();

        self::assertSame($settled->requireId(), $state->matchedUpToEntryId());
        $stored = $this->states->findForUser($this->requireUser());
        self::assertSame($settled->requireId(), $stored?->matchedUpToEntryId());
    }

    public function testEnsuringAnExistingStateKeepsItsMark(): void
    {
        $advancedMark = $this->entry('2026-11-28 09:00:00')->requireId() + 500;
        $this->store->ensureState($this->user);
        $this->states->advanceMarks([$this->user->requireId()], $advancedMark);
        $this->entityManager->clear();

        self::assertSame($advancedMark, $this->store->ensureState($this->requireUser())->matchedUpToEntryId());
    }

    public function testReplacingWordsSwapsThemWholesaleAndRecordsTheBuild(): void
    {
        $state = $this->store->ensureState($this->user);

        $this->store->replaceWords($state, 'x', [$this->word('alpha'), $this->word('beta')]);
        $this->store->replaceWords($state, 'y', [$this->word('gamma')]);
        $this->entityManager->clear();

        self::assertSame(['gamma'], $this->readStems($this->user));
        $stored = $this->states->findForUser($this->requireUser());
        self::assertNotNull($stored);
        $minuteAgo = new \DateTimeImmutable('2026-11-28 09:59:00');
        self::assertTrue($stored->isBuiltFrom('y', $minuteAgo));
        self::assertFalse($stored->isBuiltFrom('x', $minuteAgo));
        self::assertFalse($stored->isBuiltFrom('y', new \DateTimeImmutable('2026-11-28 10:00:00')));
    }

    public function testAStateNeverBuiltIsBuiltFromNothing(): void
    {
        $state = $this->store->ensureState($this->user);

        self::assertFalse($state->isBuiltFrom('', new \DateTimeImmutable('2000-01-01 00:00:00')));
    }

    public function testARebuildLeavesTheMarkWhereItIs(): void
    {
        $state = $this->store->ensureState($this->user);
        $this->states->advanceMarks([$this->user->requireId()], 25);

        $this->store->replaceWords($state, 'x', [$this->word('alpha')]);
        $this->entityManager->clear();

        self::assertSame(25, $this->states->findForUser($this->requireUser())?->matchedUpToEntryId());
    }

    public function testRecordingACheckLeavesTheBuildAndTheWords(): void
    {
        $state = $this->store->ensureState($this->user);
        $this->store->replaceWords($state, 'x', [$this->word('alpha')]);
        $this->clock->modify('+2 hours');

        $this->store->recordChecked($state);
        $this->entityManager->clear();

        self::assertSame(['alpha'], $this->readStems($this->user));
        self::assertTrue($this->states->findForUser($this->requireUser())?->isBuiltFrom(
            'x',
            new \DateTimeImmutable('2026-11-28 09:59:00'),
        ));
    }

    public function testASurfaceLongerThanAWordColumnIsCut(): void
    {
        $state = $this->store->ensureState($this->user);

        $this->store->replaceWords($state, 'x', [
            new StreamWordModel(FeedLanguage::German, 'lang', str_repeat('ä', 120), 1.0, 2.0),
        ]);
        $this->entityManager->clear();

        [$word] = $this->words->findForUser($this->user->requireId());
        self::assertSame(YourStreamWord::WORD_MAX_LENGTH, mb_strlen($word->getWord()));
        self::assertSame(FeedLanguage::German, $word->getLanguage());
        self::assertSame(1.0, $word->getWeight());
        self::assertSame(2.0, $word->getRarity());
    }

    public function testAnotherUsersWordsAreUntouched(): void
    {
        $other = new User('other-words@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->store->replaceWords($this->store->ensureState($other), 'x', [$this->word('solar')]);

        $this->store->replaceWords($this->store->ensureState($this->user), 'y', [$this->word('wind')]);

        self::assertSame(['solar'], $this->readStems($other));
    }

    private function word(string $stem): StreamWordModel
    {
        return new StreamWordModel(FeedLanguage::English, $stem, $stem, 0.5, 1.0);
    }

    /** @return list<string> */
    private function readStems(User $user): array
    {
        return array_map(
            static fn (YourStreamWord $word): string => $word->getStem(),
            $this->words->findForUser($user->requireId()),
        );
    }

    private function requireUser(): User
    {
        $user = $this->entityManager->find(User::class, $this->user->requireId());
        self::assertNotNull($user);

        return $user;
    }

    private function entry(string $createdAt): Entry
    {
        $guid = 'store-' . $this->nextEntry++;
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
