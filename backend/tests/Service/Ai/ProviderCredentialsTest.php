<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\ProviderCredentials;
use PHPUnit\Framework\TestCase;

/**
 * authorizationHeaders() is the one place the "empty key means no header"
 * rule lives — both outbound callers merge its result rather than deciding
 * this themselves.
 */
final class ProviderCredentialsTest extends TestCase
{
    public function testAnEmptyKeyProducesNoAuthorizationHeader(): void
    {
        $credentials = ProviderCredentials::fromStoredConfiguration('https://api.example.test/v1', '');

        self::assertSame([], $credentials->authorizationHeaders());
    }

    public function testAPresentKeyProducesTheBearerHeader(): void
    {
        $credentials = ProviderCredentials::fromStoredConfiguration('https://api.example.test/v1', 'sk-test');

        self::assertSame(['Authorization' => 'Bearer sk-test'], $credentials->authorizationHeaders());
    }
}
