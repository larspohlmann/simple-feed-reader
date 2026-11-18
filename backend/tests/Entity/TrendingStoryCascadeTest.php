<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingStoryCascadeTest extends DbTestCase
{
    private const array USER_TABLES = [
        'trending_story',
        'trending_story_entry',
        'trending_story_placement',
        'trending_snapshot',
        'trending_story_follow',
        'trending_story_hide',
    ];

    public function testDeletingAUserRemovesTheirTrendingRows(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $leaving = $fixtures->reader('cascade-leaving@example.com');
        $staying = $fixtures->reader('cascade-staying@example.com');
        $feed = $fixtures->feed('cascade');
        $entry = $fixtures->entry($feed, 'Cascade headline', '2031-05-14 10:00:00');
        $this->entityManager->flush();
        $this->seedTrending($leaving, $entry->requireId(), $feed->requireId());
        $this->seedTrending($staying, $entry->requireId(), $feed->requireId());

        $this->entityManager->remove($leaving);
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        foreach (self::USER_TABLES as $table) {
            self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM ' . $table), $table);
        }
        self::assertEquals(
            1,
            $connection->fetchOne(
                'SELECT COUNT(*) FROM trending_story_placement WHERE user_id = ?',
                [$staying->requireId()],
            ),
        );
    }

    private function seedTrending(User $user, int $entryId, int $feedId): void
    {
        $connection = $this->entityManager->getConnection();
        $userId = $user->requireId();
        $moment = '2031-05-14 10:00:00';
        $connection->insert('trending_story', [
            'user_id' => $userId,
            'first_seen' => $moment,
            'last_seen' => $moment,
            'first_joined_at' => $moment,
            'last_joined_at' => $moment,
        ]);
        $storyId = (int) $connection->lastInsertId();
        $connection->insert('trending_story_entry', [
            'story_id' => $storyId,
            'entry_id' => $entryId,
            'feed_id' => $feedId,
            'joined_at' => $moment,
        ]);
        $connection->insert('trending_story_placement', [
            'user_id' => $userId,
            'time_window' => TrendingWindow::Day->value,
            'entry_id' => $entryId,
            'story_id' => $storyId,
        ]);
        $connection->insert('trending_snapshot', [
            'user_id' => $userId,
            'time_window' => TrendingWindow::Day->value,
            'built_at' => $moment,
            'entry_count' => 1,
            'active_feed_count' => 1,
            'story_count' => 1,
            'duration_ms' => 0,
            'peak_memory_bytes' => 0,
        ]);
        $connection->insert('trending_story_follow', [
            'user_id' => $userId,
            'story_id' => $storyId,
            'followed_at' => $moment,
            'last_opened_at' => $moment,
        ]);
        $connection->insert('trending_story_hide', [
            'user_id' => $userId,
            'story_id' => $storyId,
            'hidden_at' => $moment,
        ]);
    }
}
