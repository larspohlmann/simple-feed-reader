<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

final class PinnedRequestOptions
{
    /** @param array<mixed> $options */
    public static function assertPersistentCappedAndPinned(array $options): void
    {
        $extra = $options['extra'] ?? null;
        Assert::assertIsArray($extra);
        Assert::assertSame(5.0, $options['max_connect_duration'] ?? null);
        Assert::assertTrue($extra['use_persistent_connections'] ?? null);
        Assert::assertIsArray($extra['curl'] ?? null);
        Assert::assertArrayHasKey(\CURLOPT_CONNECT_TO, $extra['curl']);
    }

    /** @param array<mixed> $options */
    public static function connectTo(array $options): string
    {
        /** @var array{curl: array<int, list<string>>} $extra */
        $extra = $options['extra'];

        return $extra['curl'][\CURLOPT_CONNECT_TO][0];
    }

    /** @param array<mixed> $options */
    public static function addressesOf(array $options, string $host): string
    {
        /** @var array<string, string> $resolve */
        $resolve = $options['resolve'];

        return $resolve[$host];
    }

    private function __construct()
    {
    }
}
