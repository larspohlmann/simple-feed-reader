<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Persistence;

use App\Entity\Feed;
use App\Service\Foundation\Persistence\ManagerReopener;
use App\Tests\DbTestCase;

final class ManagerReopenerTest extends DbTestCase
{
    public function testAClosedManagerIsReopenedAndFlushesAgain(): void
    {
        $this->entityManager->close();

        (new ManagerReopener($this->registry()))->ensureManagerOpen();

        self::assertTrue($this->entityManager->isOpen());
        $this->entityManager->persist(new Feed('https://example.com/reopened.xml'));
        $this->entityManager->flush();
        self::assertNotNull($this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => 'https://example.com/reopened.xml',
        ]));
    }

    public function testAnOpenManagerIsKeptWithWhatItHolds(): void
    {
        $feed = new Feed('https://example.com/kept.xml');
        $this->entityManager->persist($feed);

        (new ManagerReopener($this->registry()))->ensureManagerOpen();

        self::assertTrue($this->entityManager->contains($feed));
    }
}
