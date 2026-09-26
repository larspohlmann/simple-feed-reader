<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\PasskeyJson;
use App\Service\Passkey\AccountPasskeys;
use PHPUnit\Framework\TestCase;

final class PasskeyJsonTest extends TestCase
{
    public function testAnAccountWithoutPasskeysStillNamesItsRelyingParty(): void
    {
        self::assertSame(
            ['rpId' => 'reader.example.test', 'userHandle' => null, 'acceptedCredentialIds' => [], 'passkeys' => []],
            PasskeyJson::listing(new AccountPasskeys('reader.example.test', null, [])),
        );
    }
}
