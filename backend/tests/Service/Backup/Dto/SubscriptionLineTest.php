<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Tag;
use App\Enum\SourceFormat;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Social\Model\ExportedSubscriptionModel;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class SubscriptionLineTest extends TestCase
{
    public function testItWritesItsSettingsAndItsTagsInPositionOrder(): void
    {
        $user = BackupLineRecords::user();
        $subscription = BackupLineRecords::subscription(new Feed('https://one.example/feed.xml'));
        $subscription->setCustomTitle('Mine');
        $subscription->setPosition(3);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-08-01T02:00:00+02:00'));
        $subscription->changeInclusions(new InclusionChange(forYou: false));
        $subscription->changeInclusions(new InclusionChange(trending: false));
        $subscription->addTag(new Tag($user, 'Reading'), 1);
        $subscription->addTag(new Tag($user, 'Science'), 0);
        $exported = new ExportedSubscriptionModel($subscription, 'https://one.example/rss', SourceFormat::XML);

        self::assertSame(
            '{"kind":"subscription","feedUrl":"https://one.example/rss","customTitle":"Mine","position":3,'
            . '"markedReadUntil":"2026-08-01T00:00:00+00:00","createdAt":"2026-07-01T00:00:00+00:00",'
            . '"tags":[{"name":"Science","position":0},{"name":"Reading","position":1}],'
            . '"includeInAllItems":true,"includeInForYou":false,"includeInSavedSearches":true,'
            . '"includeInTrending":false}',
            SubscriptionLine::of($exported)->toBackupLine(),
        );
    }
}
