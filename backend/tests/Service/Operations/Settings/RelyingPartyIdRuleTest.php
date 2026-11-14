<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\RelyingPartyIdRule;
use PHPUnit\Framework\TestCase;

final class RelyingPartyIdRuleTest extends TestCase
{
    private RelyingPartyIdRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RelyingPartyIdRule();
    }

    public function testASingleLabelRelyingPartyIdIsRefused(): void
    {
        self::assertFalse($this->rule->isUsable('com'));
    }

    public function testAnIpAddressIsRefused(): void
    {
        self::assertFalse($this->rule->isUsable('203.0.113.5'));
    }

    public function testLocalhostIsAccepted(): void
    {
        self::assertTrue($this->rule->isUsable('localhost'));
    }

    public function testAnOrdinaryDomainIsAccepted(): void
    {
        self::assertTrue($this->rule->isUsable('reader.example.com'));
    }

    /**
     * The point of the rewrite: the server does not know which origin the
     * browser is at, so any domain the admin can name is accepted and the
     * browser enforces the real match.
     */
    public function testADomainUnrelatedToThisServerIsAccepted(): void
    {
        self::assertTrue($this->rule->isUsable('green-tara.aardvark-koi.ts.net'));
    }
}
