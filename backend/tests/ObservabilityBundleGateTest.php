<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ObservabilityBundleGateTest extends KernelTestCase
{
    public function testNoOpenTelemetryBundleIsRegistered(): void
    {
        self::bootKernel();

        $bundleNames = array_keys(self::$kernel->getBundles());

        foreach ($bundleNames as $bundleName) {
            self::assertStringNotContainsString('OpenTelemetry', $bundleName);
        }
    }
}
