<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** A subscribed feed of dated entries, and the entries a list response carries. For an ApiTestCase. */
trait SeedsFeedEntries
{
    private function seedFeedWithEntries(User $user, int $count): Subscription
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $feed->setFaviconUrl('https://icon.example.com/f.png');
        $entityManager->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $entityManager->persist($subscription);

        for ($index = 1; $index <= $count; $index++) {
            $publishedAt = new \DateTimeImmutable(sprintf('2026-07-%02dT00:00:00Z', $index));
            $entry = new Entry(
                $feed,
                "g$index",
                "https://example.com/$index",
                "Post $index",
                new \DateTimeImmutable('2026-07-01T00:00:00Z'),
                $publishedAt,
            );
            $entry->setPublishedAt($publishedAt);
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        return $subscription;
    }

    /** @return list<mixed> */
    private function entriesOf(KernelBrowser $client): array
    {
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['entries']);

        return array_values($body['entries']);
    }
}
