<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Reader\SearchMarkReadService;
use App\Tests\DbTestCase;

final class SearchMarkReadServiceTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('searcher@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    private function service(): SearchMarkReadService
    {
        $service = self::getContainer()->get(SearchMarkReadService::class);
        self::assertInstanceOf(SearchMarkReadService::class, $service);

        return $service;
    }

    private function entry(
        string $guid,
        string $title,
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
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function stateFor(Entry $entry, bool $isHidden): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden($isHidden);
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    private function stateOf(Entry $entry): ?EntryState
    {
        // Bulk DQL writes bypass the identity map: clear it first so this read
        // does not serve a stale pre-mark instance (the project's "bulk DQL
        // fools tests" gotcha).
        $this->em->clear();

        return $this->em->getRepository(EntryState::class)
            ->findOneBy(['user' => $this->user->getId(), 'entry' => $entry->getId()]);
    }

    public function testCreatesAStateRowForAMatchWithNoExistingState(): void
    {
        $entry = $this->entry('a', 'Klima report');

        $this->service()->mark($this->user, 'klima', new \DateTimeImmutable('2100-01-01'));

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
    }

    public function testFlipsAnExplicitlyUnreadMatch(): void
    {
        $entry = $this->entry('b', 'Klima update');
        $this->stateFor($entry, false);

        $this->service()->mark($this->user, 'klima', new \DateTimeImmutable('2100-01-01'));

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
    }

    public function testLeavesAnAlreadyReadMatchUnchanged(): void
    {
        $entry = $this->entry('c', 'Klima old');
        $existing = $this->stateFor($entry, true);
        $hiddenAt = $existing->getHiddenAt();

        $this->service()->mark($this->user, 'klima', new \DateTimeImmutable('2100-01-01'));

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
        self::assertEquals($hiddenAt, $state->getHiddenAt());
    }

    public function testLeavesANonMatchUnread(): void
    {
        $entry = $this->entry('d', 'Cooking tips');

        $this->service()->mark($this->user, 'klima', new \DateTimeImmutable('2100-01-01'));

        self::assertNull($this->stateOf($entry));
    }

    public function testWholeWordQueryMarksOnlyWholeWordMatches(): void
    {
        $whole = $this->entry('e', 'A punk revival');
        $substring = $this->entry('f', 'Steampunk gadgets');

        $this->service()->mark($this->user, 'punk ', new \DateTimeImmutable('2100-01-01'));

        $wholeState = $this->stateOf($whole);
        self::assertNotNull($wholeState);
        self::assertTrue($wholeState->isHidden());
        self::assertNull($this->stateOf($substring));
    }

    public function testAMatchNewerThanUntilStaysUnread(): void
    {
        $entry = $this->entry('g', 'Klima forecast', '2026-08-01T00:00:00Z');

        $this->service()->mark($this->user, 'klima', new \DateTimeImmutable('2026-07-15T00:00:00Z'));

        self::assertNull($this->stateOf($entry));
    }
}
