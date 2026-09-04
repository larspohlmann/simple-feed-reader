<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\ResolvedMailTransport;
use PHPUnit\Framework\TestCase;

final class ResolvedMailTransportTest extends TestCase
{
    public function testTheSignatureNamesEveryConnectionFieldAndADigestOfThePassword(): void
    {
        $withPassword = new ResolvedMailTransport('smtp.test', 2525, 'alice', 'hunter2', MailEncryption::Tls);
        $withoutPassword = new ResolvedMailTransport('smtp.test', 2525, null, null, MailEncryption::Starttls);

        $expectedWithPassword = 'smtp.test|2525|alice|tls|' . hash('sha256', 'hunter2') . '|direct';
        self::assertSame($expectedWithPassword, $withPassword->signature());
        self::assertSame('smtp.test|2525||starttls|no-pass|direct', $withoutPassword->signature());
    }

    public function testARotatedPasswordChangesTheSignatureWithoutExposingIt(): void
    {
        $before = new ResolvedMailTransport('smtp.test', 2525, 'alice', 'hunter2', MailEncryption::Tls);
        $after = new ResolvedMailTransport('smtp.test', 2525, 'alice', 'hunter3', MailEncryption::Tls);

        self::assertNotSame($before->signature(), $after->signature());
        self::assertStringNotContainsString('hunter3', $after->signature());
    }

    public function testSignatureDiffersWhenProxyRoutingDiffers(): void
    {
        $direct = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, false);
        $proxied = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, true);
        self::assertNotSame($direct->signature(), $proxied->signature());
    }
}
