<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailCapability;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MailCapabilityTest constructs the service by hand, so it can never catch a
 * container-wiring regression. This test drives the REAL container to prove
 * the service still constructs and resolves its MailSettings collaborator.
 */
final class MailCapabilityWiringTest extends KernelTestCase
{
    public function testResolvesFromTheContainer(): void
    {
        self::bootKernel();
        $capability = self::getContainer()->get(MailCapability::class);

        // The test env fallback is null://null and no row exists: derived off.
        self::assertFalse($capability->isEnabled());
    }
}
