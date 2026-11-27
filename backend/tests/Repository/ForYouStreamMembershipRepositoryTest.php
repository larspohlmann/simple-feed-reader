<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ForYouStreamEntry;
use App\Entity\User;
use App\Repository\ForYouStreamMembershipRepository;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamMatchModel;
use App\Service\Curation\Recommendation\Stream\Model\StreamScoreModel;
use App\Tests\DbTestCase;

final class ForYouStreamMembershipRepositoryTest extends DbTestCase
{
    private ForYouStreamMembershipRepository $memberships;
    private User $user;
    private Entry $first;
    private Entry $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('stream-rows@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $feed = new Feed('https://example.com/rows.xml');
        $this->entityManager->persist($feed);
        $date = new \DateTimeImmutable('2026-11-27T08:00:00Z');
        $this->first = new Entry($feed, 'first', null, 'First', $date, $date);
        $this->second = new Entry($feed, 'second', null, 'Second', $date, $date);
        $this->entityManager->persist($this->first);
        $this->entityManager->persist($this->second);
        $this->entityManager->flush();
        $memberships = self::getContainer()->get(ForYouStreamMembershipRepository::class);
        self::assertInstanceOf(ForYouStreamMembershipRepository::class, $memberships);
        $this->memberships = $memberships;
    }

    public function testInsertMissingSkipsStoredPairsOnBothPlatforms(): void
    {
        $at = new \DateTimeImmutable('2026-11-27 10:00:00');
        $stored = $this->memberships->insertMissing([$this->match($this->first, 2.5, ['solar', 'grid'])], $at);
        $again = $this->memberships->insertMissing(
            [$this->match($this->first, 9.0, ['other']), $this->match($this->second, 1.5, ['wind'])],
            $at->modify('+1 hour'),
        );

        self::assertSame(1, $stored);
        self::assertSame(1, $again);
        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(ForYouStreamEntry::class)->findBy([], ['entry' => 'ASC']);
        self::assertCount(2, $rows);
        self::assertEqualsWithDelta(2.5, $rows[0]->getScore(), 1e-6);
        self::assertSame('solar grid', $rows[0]->getMatchedStems());
        self::assertEquals($at, $rows[0]->getMatchedAt());
    }

    public function testNothingToInsertIsNothingInserted(): void
    {
        self::assertSame(0, $this->memberships->insertMissing([], new \DateTimeImmutable('2026-11-27 10:00:00')));
    }

    public function testDeletingTheEntryDeletesItsRows(): void
    {
        $this->memberships->insertMissing(
            [$this->match($this->first, 2.0, ['solar']), $this->match($this->second, 2.0, ['solar'])],
            new \DateTimeImmutable('2026-11-27 10:00:00'),
        );
        $secondId = $this->second->requireId();

        $this->entityManager->remove($this->first);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->entityManager->getRepository(ForYouStreamEntry::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame($secondId, $rows[0]->getEntry()->requireId());
    }

    /** @param list<string> $stems */
    private function match(Entry $entry, float $score, array $stems): ForYouStreamMatchModel
    {
        return new ForYouStreamMatchModel(
            $this->user->requireId(),
            $entry->requireId(),
            new StreamScoreModel($score, $stems),
        );
    }
}
