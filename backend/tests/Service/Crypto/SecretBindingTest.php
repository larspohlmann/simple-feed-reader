<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\SecretBinding;
use PHPUnit\Framework\TestCase;

/** The rendered binding is stored-data contract: these strings must never change. */
final class SecretBindingTest extends TestCase
{
    public function testAUserBindingRendersThePurposeVersionAndUser(): void
    {
        self::assertSame('ai-api-key|v1|user:42', SecretBinding::forUser('ai-api-key', 42)->render(1));
    }

    public function testAnInstanceBindingRendersThePurposeVersionAndInstance(): void
    {
        self::assertSame('proxy-password|v2|instance', SecretBinding::forInstance('proxy-password')->render(2));
    }
}
