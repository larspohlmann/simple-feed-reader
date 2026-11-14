<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto\Model;

use App\Service\Crypto\Model\SecretChangeModel;
use PHPUnit\Framework\TestCase;

final class SecretChangeModelTest extends TestCase
{
    public function testKeepNeitherReplacesNorRemoves(): void
    {
        $change = SecretChangeModel::keep();

        self::assertNull($change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testReplaceWithCarriesTheNewSecret(): void
    {
        $change = SecretChangeModel::replaceWith('sw0rdfish');

        self::assertSame('sw0rdfish', $change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testRemoveCarriesNoSecret(): void
    {
        $change = SecretChangeModel::remove();

        self::assertNull($change->replacement());
        self::assertTrue($change->isRemoval());
    }

    public function testFromSubmittedKeepsOnNull(): void
    {
        $change = SecretChangeModel::fromSubmitted(null);

        self::assertNull($change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testFromSubmittedReplacesOnAnEmptyString(): void
    {
        $change = SecretChangeModel::fromSubmitted('');

        self::assertSame('', $change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testFromSubmittedReplacesOnAString(): void
    {
        $change = SecretChangeModel::fromSubmitted('x');

        self::assertSame('x', $change->replacement());
        self::assertFalse($change->isRemoval());
    }
}
