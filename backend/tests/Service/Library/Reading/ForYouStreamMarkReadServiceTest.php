<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\ForYouStreamEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Library\Reading\ForYouStreamMarkReadService;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsUsers;

final class ForYouStreamMarkReadServiceTest extends DbTestCase
{
    use RequiresServices;
    use SeedsUsers;

    public function testOnlyTheCallersMatchesAreMarked(): void
    {
        $reader = $this->user('stream-mark-reader@example.com');
        $other = $this->user('stream-mark-other@example.com');
        $feed = new Feed('https://example.com/stream-mark.xml');
        $this->entityManager->persist($feed);
        $published = new \DateTimeImmutable('2026-11-27T08:00:00Z');
        $entry = new Entry($feed, 'shared', null, 'Shared', $published, $published);
        $this->entityManager->persist($entry);
        foreach ([$reader, $other] as $account) {
            $this->entityManager->persist(new Subscription($account, $feed, $published));
            $this->entityManager->persist(
                new ForYouStreamEntry($account, $entry, new \DateTimeImmutable('2026-11-27 09:00:00'), 1.0, 'stem'),
            );
        }
        $this->entityManager->flush();

        $this->requireService()->mark($reader, new \DateTimeImmutable('2026-11-27T12:00:00Z'));

        $this->entityManager->clear();
        self::assertTrue($this->isRead($reader, $entry));
        self::assertFalse($this->isRead($other, $entry));
    }

    private function isRead(User $account, Entry $entry): bool
    {
        $state = $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($account->requireId(), $entry->requireId());

        return $state?->isHidden() === true;
    }

    private function requireService(): ForYouStreamMarkReadService
    {
        return $this->requireFromContainer(ForYouStreamMarkReadService::class);
    }
}
