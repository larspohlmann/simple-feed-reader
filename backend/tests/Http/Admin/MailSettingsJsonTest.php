<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Mail\Settings\MailConnection;
use PHPUnit\Framework\TestCase;

final class MailSettingsJsonTest extends TestCase
{
    public function testWithNoRowThePayloadIsSeededFromTheEnvFallbackWithoutAPassword(): void
    {
        $fallback = new MailConnection(true, 'smtp.env.test', 2525, 'env-user', MailEncryption::Tls, 'a@env', 'Env');

        self::assertSame([
            'enabled' => true,
            'host' => 'smtp.env.test',
            'port' => 2525,
            'username' => 'env-user',
            'encryption' => 'tls',
            'fromAddress' => 'a@env',
            'fromName' => 'Env',
            'hasPassword' => false,
            'passwordHint' => '',
            'hasSavedConfig' => false,
            'envFallbackConfigured' => true,
        ], MailSettingsJson::from(null, $fallback));
    }

    public function testWithARowThePayloadIsTheRowPlusTheHintAndTheFallbackFlag(): void
    {
        $settings = new MailServerSettings();
        $settings->apply(
            new MailConnection(false, 'smtp.row.test', 465, null, MailEncryption::None, 'a@row', 'Row'),
            new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'fish',
        );
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');

        self::assertSame([
            'enabled' => false,
            'host' => 'smtp.row.test',
            'port' => 465,
            'username' => null,
            'encryption' => 'none',
            'fromAddress' => 'a@row',
            'fromName' => 'Row',
            'hasPassword' => true,
            'passwordHint' => 'fish',
            'hasSavedConfig' => true,
            'envFallbackConfigured' => false,
        ], MailSettingsJson::from($settings, $fallback));
    }
}
