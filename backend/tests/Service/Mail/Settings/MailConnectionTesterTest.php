<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailConnectionTesterTest extends KernelTestCase
{
    private function tester(): MailConnectionTester
    {
        return self::getContainer()->get(MailConnectionTester::class);
    }

    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    public function testItReportsNotConfiguredWithNoSavedRow(): void
    {
        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertSame('not_configured', $result->reason);
    }

    public function testItReportsTheTransportErrorWhenTheServerIsUnreachable(): void
    {
        $this->settings()->update(
            new MailSettingsRequest(host: '127.0.0.1', port: 0, fromAddress: 'from@x.test', password: 'p'),
        );

        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertNotNull($result->reason);
    }
}
