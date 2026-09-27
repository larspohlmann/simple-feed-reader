<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Service\Crypto\SealedSecret;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettingsSnapshot;
use PHPUnit\Framework\TestCase;

final class MailSettingsSnapshotTest extends TestCase
{
    public function testItCarriesTheRowsConnectionAndWhetherAPasswordIsStored(): void
    {
        $connection = new MailConnection(
            true,
            'smtp.row.test',
            465,
            'user',
            MailEncryption::Tls,
            'a@row.test',
            'Row',
            true,
        );
        $row = new MailServerSettings();
        $row->apply($connection, new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1));

        $snapshot = MailSettingsSnapshot::fromEntity($row);

        self::assertEquals($connection, $snapshot->connection);
        self::assertTrue($snapshot->hasPassword);
    }

    public function testARowWithoutAPasswordSaysSo(): void
    {
        $row = new MailServerSettings();
        $row->applyWithoutPassword(
            new MailConnection(false, 'smtp.row.test', 587, null, MailEncryption::None, '', ''),
        );

        self::assertFalse(MailSettingsSnapshot::fromEntity($row)->hasPassword);
    }
}
