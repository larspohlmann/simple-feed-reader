<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use PHPUnit\Framework\TestCase;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Cheap, and it earns its place: the ceremony managers are built lazily at
 * runtime (see PasskeyCeremony), so a missing or moved library class would
 * otherwise first surface as a 500 during a real sign-in.
 */
final class LibraryIsInstalledTest extends TestCase
{
    public function testTheCeremonyStepManagerFactoryIsAutoloadable(): void
    {
        self::assertTrue(class_exists(CeremonyStepManagerFactory::class));
    }
}
