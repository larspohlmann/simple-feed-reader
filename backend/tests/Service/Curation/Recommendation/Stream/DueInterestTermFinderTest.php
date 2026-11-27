<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Entity\InterestTermSet;
use App\Entity\User;
use App\Service\Curation\Recommendation\Stream\DueInterestTermFinder;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;

final class DueInterestTermFinderTest extends DbTestCase
{
    use SeedsProfiledAccounts;
    use SeedsUsers;

    public function testAnAccountWithAProfileAndNoTermsIsDue(): void
    {
        $owner = $this->profiledAccount('terms-due-new@example.test');

        $due = $this->findDue();

        self::assertCount(1, $due);
        self::assertSame($owner->getEmail(), $due[0]->user->getEmail());
        self::assertSame('Likes solar power.', $due[0]->profileText);
        self::assertEquals($this->profileGeneratedAt(), $due[0]->profileGeneratedAt);
        self::assertSame($this->requireConnection($owner), $due[0]->connection);
    }

    public function testTermsAsNewAsTheProfileAreNotDue(): void
    {
        $owner = $this->profiledAccount('terms-due-current@example.test');
        $this->storeProfileTerms($owner, $this->profileGeneratedAt());

        self::assertSame([], $this->findDue());
    }

    public function testTermsOlderThanTheProfileAreDue(): void
    {
        $owner = $this->profiledAccount('terms-due-stale@example.test');
        $this->storeProfileTerms($owner, new \DateTimeImmutable('2026-10-02 06:00:00'));

        self::assertSame(['terms-due-stale@example.test'], $this->dueEmails());
    }

    public function testAFailedRunForThisProfileStopsItUntilTheProfileChanges(): void
    {
        $owner = $this->profiledAccount('terms-due-failed@example.test');
        $this->persistRun($owner, $this->profileGeneratedAt())->fail('gave up', new \DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame([], $this->findDue());

        $this->fixtures()->storeProfileGeneratedAt($owner, 'Likes wind power.', '2026-10-04 06:00:00');

        self::assertSame(['terms-due-failed@example.test'], $this->dueEmails());
    }

    public function testARunStillGoingForThisProfileKeepsItDue(): void
    {
        $owner = $this->profiledAccount('terms-due-running@example.test');
        $this->persistRun($owner, $this->profileGeneratedAt());
        $this->entityManager->flush();

        self::assertSame(['terms-due-running@example.test'], $this->dueEmails());
    }

    public function testAnAccountWithoutAUsableConnectionIsNotDue(): void
    {
        $owner = $this->user('terms-due-noconn@example.test');
        $this->fixtures()->seedReadyScoringSettings($owner);
        $this->fixtures()->storeProfile($owner, 'Likes solar power.');

        self::assertSame([], $this->findDue());
    }

    public function testAnInactiveAccountIsNotDue(): void
    {
        $owner = $this->profiledAccount('terms-due-suspended@example.test');
        $owner->suspend();
        $this->entityManager->flush();

        self::assertSame([], $this->findDue());
    }

    /** @return list<DueInterestTermsModel> */
    private function findDue(): array
    {
        $finder = self::getContainer()->get(DueInterestTermFinder::class);
        self::assertInstanceOf(DueInterestTermFinder::class, $finder);

        return $finder->findDue();
    }

    /** @return list<string> */
    private function dueEmails(): array
    {
        return array_map(static fn (DueInterestTermsModel $due): string => $due->user->getEmail(), $this->findDue());
    }

    private function storeProfileTerms(User $owner, \DateTimeImmutable $profileGeneratedAt): void
    {
        $this->entityManager->persist(new InterestTermSet($owner, $profileGeneratedAt, $profileGeneratedAt, 0));
        $this->entityManager->flush();
    }

    private function persistRun(User $owner, \DateTimeImmutable $profileGeneratedAt): InterestTermRun
    {
        $run = new InterestTermRun($owner, $profileGeneratedAt, $profileGeneratedAt, 'api.example.test', 'qwen3-14b');
        $this->entityManager->persist($run);

        return $run;
    }
}
