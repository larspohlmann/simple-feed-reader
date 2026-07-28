<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Entry;
use App\Entity\Feed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The database stores datetimes as naive UTC, so PHP's default timezone MUST
 * be UTC in every process — otherwise Doctrine hydrates a stored value in the
 * worker's local zone and the API serializes a wrong instant. This is not
 * hypothetical: Strato's externally-routed web workers default to
 * Europe/Berlin, which shipped `publishedAt: …+02:00` and made every entry
 * render two hours older than it is (#153). Booting the kernel must pin UTC
 * regardless of what the host's ini or a previous caller set.
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
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $feed = new Feed('https://example.com/feed');
        $entry = new Entry(
            $feed,
            'guid-timezone-probe',
            'https://example.com/article',
            'Timezone probe',
            new \DateTimeImmutable('2026-07-27 23:23:05', new \DateTimeZone('UTC')),
        );
        $em->persist($feed);
        $em->persist($entry);
        $em->flush();
        $em->clear();

        $hydrated = $em->find(Entry::class, $entry->getId());
        self::assertNotNull($hydrated);

        self::assertSame(
            '2026-07-27T23:23:05+00:00',
            $hydrated->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
