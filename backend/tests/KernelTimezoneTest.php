<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Entry;
use App\Entity\Feed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Datetimes are stored as naive UTC, so booting the kernel must pin PHP's zone to UTC whatever the host's ini says:
 * Strato's web workers default to Europe/Berlin, and every entry rendered two hours old (#153).
 */
final class KernelTimezoneTest extends KernelTestCase
{
    private string $ambientTimezone;

    protected function setUp(): void
    {
        $this->ambientTimezone = date_default_timezone_get();
        // Simulate a host whose worker ini defaults to local time.
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->ambientTimezone);
        parent::tearDown();
    }

    public function testBootingTheKernelPinsUtcAsTheDefaultTimezone(): void
    {
        self::bootKernel();

        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testAStoredDatetimeHydratesAndSerializesAsUtc(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $feed = new Feed('https://example.com/feed');
        $entry = new Entry(
            $feed,
            'guid-timezone-probe',
            'https://example.com/article',
            'Timezone probe',
            new \DateTimeImmutable('2026-07-27 23:23:05', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-07-27 23:23:05', new \DateTimeZone('UTC')),
        );
        $entityManager->persist($feed);
        $entityManager->persist($entry);
        $entityManager->flush();
        $entityManager->clear();

        $hydrated = $entityManager->find(Entry::class, $entry->getId());
        self::assertNotNull($hydrated);

        self::assertSame(
            '2026-07-27T23:23:05+00:00',
            $hydrated->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
