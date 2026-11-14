<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Grafana\Crypto;

use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Operations\Grafana\Crypto\GrafanaApiKeyCipher;
use PHPUnit\Framework\TestCase;

final class GrafanaApiKeyCipherTest extends TestCase
{
    public function testSealsAndOpensRoundTrip(): void
    {
        $cipher = new GrafanaApiKeyCipher(new InstanceSecretCipher(str_repeat('k', 32)));

        $sealed = $cipher->seal('glc_secrettoken');

        self::assertNotSame('glc_secrettoken', $sealed->ciphertext);
        self::assertSame('glc_secrettoken', $cipher->open($sealed));
    }
}
