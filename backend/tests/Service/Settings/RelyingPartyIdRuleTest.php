<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\RelyingPartyIdRule;
use PHPUnit\Framework\TestCase;

/**
 * The registrable-suffix rule extracted out of RelyingPartyChange (#624
 * follow-up) so PasskeySignInAvailability can share it rather than carry a
 * second, independently written copy — see that class's own docblock for why
 * a drift here is dangerous: it would let the admin form accept a value the
 * login page then treats as broken, or the reverse.
 *
 * These cases mirror RelyingPartyChangeTest one for one; that file keeps
 * proving the rule end to end through the write-path guard, this one proves
 * the rule itself in isolation.
 */
final class RelyingPartyIdRuleTest extends TestCase
{
    private RelyingPartyIdRule $rule;

    protected function setUp(): void
    {
        $this->rule = new RelyingPartyIdRule();
    }

    public function testAHostThatMerelyEndsWithTheRelyingPartyIdStringIsRefused(): void
    {
        self::assertFalse($this->rule->isValidForHost('example.test', 'evilexample.test'));
    }

    public function testARealSubdomainOfTheRelyingPartyIdIsAccepted(): void
    {
        self::assertTrue($this->rule->isValidForHost('example.test', 'reader.example.test'));
    }

    public function testASingleLabelRelyingPartyIdIsRefused(): void
    {
        self::assertFalse($this->rule->isValidForHost('com', 'reader.example.com'));
    }

    public function testAnIpAddressRelyingPartyIdIsRefusedEvenWhenItExactlyMatchesTheHost(): void
    {
        self::assertFalse($this->rule->isValidForHost('203.0.113.5', '203.0.113.5'));
    }

    public function testAFragmentOfAnIpHostIsRefused(): void
    {
        self::assertFalse($this->rule->isValidForHost('1.5', '192.168.1.5'));
    }

    public function testLocalhostIsAccepted(): void
    {
        self::assertTrue($this->rule->isValidForHost('localhost', 'localhost'));
    }

    public function testTheIdItselfAlwaysMatchesItsOwnHost(): void
    {
        self::assertTrue($this->rule->isValidForHost('example.test', 'example.test'));
    }
}
