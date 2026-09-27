<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\SecretChange;
use PHPUnit\Framework\TestCase;

final class SecretChangeTest extends TestCase
{
    public function testKeepNeitherReplacesNorRemoves(): void
    {
        $change = SecretChange::keep();

        self::assertNull($change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testReplaceWithCarriesTheNewSecret(): void
    {
        $change = SecretChange::replaceWith('sw0rdfish');

        self::assertSame('sw0rdfish', $change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testRemoveCarriesNoSecret(): void
    {
        $change = SecretChange::remove();

        self::assertNull($change->replacement());
        self::assertTrue($change->isRemoval());
    }
}
