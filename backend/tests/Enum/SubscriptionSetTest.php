<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SourceFormat;
use App\Enum\SubscriptionSet;
use PHPUnit\Framework\TestCase;

final class SubscriptionSetTest extends TestCase
{
    public function testEverySubscriptionExcludesNoFormat(): void
    {
        self::assertSame([], SubscriptionSet::All->excludedFormats());
    }

    public function testAnOpmlExportLeavesOutWhatOnlyThisReaderCanRead(): void
    {
        self::assertSame(SourceFormat::NOT_IN_OPML, SubscriptionSet::OpmlExport->excludedFormats());
    }

    public function testABackupLeavesOutOnlyThePrivateFeeds(): void
    {
        self::assertSame(SourceFormat::SOCIAL, SubscriptionSet::Backup->excludedFormats());
    }
}
