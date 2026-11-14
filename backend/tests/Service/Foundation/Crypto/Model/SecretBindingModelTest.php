<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto\Model;

use App\Service\Crypto\Model\SecretBindingModel;
use PHPUnit\Framework\TestCase;

/** The rendered binding is stored-data contract: these strings must never change. */
final class SecretBindingModelTest extends TestCase
{
    public function testAUserBindingRendersThePurposeVersionAndUser(): void
    {
        self::assertSame('ai-api-key|v1|user:42', SecretBindingModel::forUser('ai-api-key', 42)->render(1));
    }

    public function testAnInstanceBindingRendersThePurposeVersionAndInstance(): void
    {
        self::assertSame('proxy-password|v2|instance', SecretBindingModel::forInstance('proxy-password')->render(2));
    }
}
