<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailCapability;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MailCapabilityTest constructs the service by hand, so it can never catch a
 * container-wiring regression: %env(default::MAIL_DISABLED)% resolves an
 * unset/empty var to PHP null (see EnvVarProcessor::getEnv(), the 'default'
 * branch), which the constructor's non-nullable `string $disabledFlag` would
 * reject with a TypeError on first fetch — in the default "mail on"
 * configuration, before any later task even reads the flag. The `string:`
 * cast in the env expression is what makes null become ''. This test drives
 * the REAL container to prove the service still constructs.
 */
final class MailCapabilityWiringTest extends KernelTestCase
{
    public function testResolvesFromTheContainerWithMailEnabledByDefault(): void
    {
        self::bootKernel();
        $capability = self::getContainer()->get(MailCapability::class);

        self::assertInstanceOf(MailCapability::class, $capability);
        self::assertTrue($capability->isEnabled());
    }
}
