<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ImageRendition;
use App\Repository\ProbedLadderRepository;
use App\Tests\DbTestCase;

final class ProbedLadderRepositoryTest extends DbTestCase
{
    private const string LOGO = 'https://i/podcast/logo-600x600.jpg';

    public function testAMeasuredEntryHandsOnItsLadder(): void
    {
        $this->measured('earlier', self::LOGO, [new ImageRendition('https://i/podcast/logo-512x512.jpg', 512)]);
        $this->entityManager->flush();

        self::assertEquals(
            [new ImageRendition('https://i/podcast/logo-512x512.jpg', 512)],
            $this->repository()->findProbedLadder(self::LOGO),
        );
    }

    public function testAMeasuredEntryWhoseProbeFoundNothingHandsOnAnEmptyLadder(): void
    {
        $this->measured('earlier', self::LOGO, []);
        $this->entityManager->flush();

        self::assertSame([], $this->repository()->findProbedLadder(self::LOGO));
    }

    public function testThereIsNoLadderWhileTheImageIsOnlyPending(): void
    {
        $this->entry('pending')->getImage()->storePending(self::LOGO, 600, 600);
        $this->entityManager->flush();

        self::assertNull($this->repository()->findProbedLadder(self::LOGO));
    }

    public function testAnotherImageHandsOnNothing(): void
    {
        $this->measured('other', 'https://i/podcast/other-600x600.jpg', []);
        $this->entityManager->flush();

        self::assertNull($this->repository()->findProbedLadder(self::LOGO));
    }

    public function testTheNewestMeasuredEntryWins(): void
    {
        $this->measured('older', self::LOGO, []);
        $this->measured('newer', self::LOGO, [new ImageRendition('https://i/podcast/logo-512x512.jpg', 512)]);
        $this->entityManager->flush();

        self::assertCount(1, $this->repository()->findProbedLadder(self::LOGO) ?? []);
    }

    /** @param list<ImageRendition> $renditions */
    private function measured(string $guid, string $imageUrl, array $renditions): void
    {
        $entry = $this->entry($guid);
        $entry->getImage()->storePending($imageUrl, null, null);
        $entry->getImage()->recordMeasurement(600, 600, new \DateTimeImmutable('2026-10-28 10:00:00'));
        $entry->getImage()->storeRenditions($renditions);
    }

    private function entry(string $guid): Entry
    {
        $feed = new Feed('https://example.test/feed-' . $guid . '.xml');
        $this->entityManager->persist($feed);
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-10-28 06:00:00'),
            new \DateTimeImmutable('2026-10-28 05:00:00'),
        );
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function repository(): ProbedLadderRepository
    {
        /** @var ProbedLadderRepository $repository */
        $repository = self::getContainer()->get(ProbedLadderRepository::class);

        return $repository;
    }
}
