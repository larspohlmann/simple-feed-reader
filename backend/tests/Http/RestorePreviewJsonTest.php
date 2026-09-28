<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RestorePreviewJson;
use App\Service\Backup\BackupInventory;
use App\Service\Backup\Model\RestoreSourceModel;
use App\Service\Backup\RestorePreview;
use PHPUnit\Framework\TestCase;

final class RestorePreviewJsonTest extends TestCase
{
    public function testItShapesTheWholePreview(): void
    {
        $source = new RestoreSourceModel(
            'backup-1202',
            4,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            'https://reader.example',
            'owner@example.test',
        );
        $toLoad = new BackupInventory($source, 1, 2, 3, 4, 5, 6);

        $json = RestorePreviewJson::from(new RestorePreview($source, $toLoad, 7, 8, 9, 10));

        self::assertSame([
            'backup' => [
                'backupId' => 'backup-1202',
                'parts' => 4,
                'createdAt' => '2026-07-01T00:00:00+00:00',
                'sourceUrl' => 'https://reader.example',
                'sourceEmail' => 'owner@example.test',
            ],
            'toLoad' => [
                'tags' => 1,
                'savedSearches' => 2,
                'feeds' => 3,
                'subscriptions' => 4,
                'entries' => 5,
                'entryStates' => 6,
            ],
            'toDelete' => [
                'tags' => 8,
                'subscriptions' => 7,
                'entryStates' => 9,
                'recommendationRuns' => 10,
            ],
        ], $json);
    }
}
