<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Entries and per-user states of the saved-post tests (#1666), on a real entity manager. */
final class SavedPostFixtures
{
    public const string AT = '2026-10-17 10:00:00';

    public static function entry(EntityManagerInterface $entityManager, Feed $feed, string $guid, ?string $url): Entry
    {
        $at = new \DateTimeImmutable(self::AT);
        $entry = new Entry($feed, $guid, $url, 'Title ' . $guid, $at, $at, null === $url ? null : hash('sha256', $url));
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    public static function state(EntityManagerInterface $entityManager, User $user, Entry $entry): EntryState
    {
        $state = new EntryState($user, $entry);
        $entityManager->persist($state);

        return $state;
    }

    private function __construct()
    {
    }
}
