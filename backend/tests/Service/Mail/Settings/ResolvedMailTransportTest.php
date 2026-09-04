<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\ResolvedMailTransport;
use PHPUnit\Framework\TestCase;

final class ResolvedMailTransportTest extends TestCase
{
    public function testTheSignatureNamesEveryConnectionFieldButNeverThePassword(): void
    {
        $withPassword = new ResolvedMailTransport('smtp.test', 2525, 'alice', 'hunter2', MailEncryption::Tls);
        $withoutPassword = new ResolvedMailTransport('smtp.test', 2525, null, null, MailEncryption::Starttls);

        self::assertSame('smtp.test|2525|alice|tls|has-pass', $withPassword->signature());
        self::assertSame('smtp.test|2525||starttls|no-pass', $withoutPassword->signature());
    }
}
