<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupLineOrder;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupLineOrderTest extends TestCase
{
    public function testTheFirstLineMustBeAHeader(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The first line must be a header.');

        BackupLineOrder::beforeTheFirstLine()->admit('account', 1);
    }

    public function testAnUnknownKindIsRefusedBeforeTheFirstLineRule(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 1 has an unknown kind "bogus".');

        BackupLineOrder::beforeTheFirstLine()->admit('bogus', 1);
    }

    public function testAnUnknownKindLaterNamesItsLine(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 3 has an unknown kind "bogus".');

        BackupLineOrder::beforeTheFirstLine()->admit('header', 1)->admit('account', 2)->admit('bogus', 3);
    }

    public function testARankMayNotMoveBackwards(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 4 is out of order.');

        BackupLineOrder::beforeTheFirstLine()
            ->admit('header', 1)
            ->admit('account', 2)
            ->admit('feed', 3)
            ->admit('tag', 4);
    }

    public function testASingletonKindMayNotRepeat(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Line 3 repeats the singleton kind "account".');

        BackupLineOrder::beforeTheFirstLine()->admit('header', 1)->admit('account', 2)->admit('account', 3);
    }

    public function testARepeatableKindMayRepeatAndTheFooterCloses(): void
    {
        $this->expectNotToPerformAssertions();

        BackupLineOrder::beforeTheFirstLine()
            ->admit('header', 1)
            ->admit('account', 2)
            ->admit('feed', 3)
            ->admit('feed', 4)
            ->admit('footer', 5);
    }

    public function testAdmittingLeavesTheEarlierOrderAsItWas(): void
    {
        $start = BackupLineOrder::beforeTheFirstLine();
        $start->admit('header', 1);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The first line must be a header.');

        $start->admit('account', 2);
    }
}
