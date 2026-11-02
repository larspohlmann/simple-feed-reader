<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\PostRemovalRepository;
use App\Repository\RowIds;
use App\Service\Social\ConfirmedDeletion;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\HomeServerCheck\HomeServerCheckInterface;
use App\Service\Social\LocalPostRemoval;
use App\Service\Social\SocialCountsCache;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ConfirmedDeletions
{
    public static function askingOf(
        EntityManagerInterface $entityManager,
        HomeServerCheckInterface $homeServer,
    ): ConfirmedDeletion {
        $askHomeServer = static fn (): HomeServerCheckInterface => $homeServer;

        return new ConfirmedDeletion(
            new LocalPostRemoval(
                new PostRemovalRepository($entityManager, new RowIds($entityManager)),
                new DeletedPostTombstones(new ArrayAdapter(), new MockClock('2026-11-02 12:00:00')),
                EntryIndexers::with(new RecordingSearchIndexWriter()),
                new SocialCountsCache(new ArrayAdapter()),
            ),
            new ServiceLocator(['mastodon' => $askHomeServer, 'bluesky' => $askHomeServer]),
        );
    }
}
