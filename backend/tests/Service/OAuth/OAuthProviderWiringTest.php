<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\OAuthProvider\AppleOAuthProvider;
use App\Service\OAuth\OAuthProvider\GoogleOAuthProvider;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Tests\Support\AppleTestKey;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The unit tests build the registry by hand; this proves the container collects both providers. Apple's key comes
 * from AppleTestKey via $_ENV before boot, so `.env.test` keeps no multi-line PEM and the key has one source.
 */
final class OAuthProviderWiringTest extends KernelTestCase
{
    private const APPLE_KEY_VAR = 'APPLE_OAUTH_PRIVATE_KEY';

    /** @var array{bool, string|null} */
    private array $originalAppleKey = [false, null];

    protected function setUp(): void
    {
        parent::setUp();

        $existing = $_ENV[self::APPLE_KEY_VAR] ?? null;
        $this->originalAppleKey = [
            \array_key_exists(self::APPLE_KEY_VAR, $_ENV),
            \is_string($existing) ? $existing : null,
        ];
    }

    protected function tearDown(): void
    {
        // Restore before the next test boots a kernel: a leaked key would make
        // some later test believe this deployment offers Apple.
        [$existed, $value] = $this->originalAppleKey;
        if ($existed) {
            $_ENV[self::APPLE_KEY_VAR] = $value;
        } else {
            unset($_ENV[self::APPLE_KEY_VAR]);
        }

        parent::tearDown();
    }

    public function testTheContainerCollectsBothProviders(): void
    {
        $_ENV[self::APPLE_KEY_VAR] = AppleTestKey::privateKey();

        $registry = $this->registry();

        $names = $registry->getConfiguredNames();
        // Sorted: the button order is pinned in OAuthProviderRegistryTest; here it is only the container's scan order.
        sort($names);

        self::assertSame(['apple', 'google'], $names);

        // Not just the right names — the real wired services. A registry that
        // somehow collected two stand-ins would satisfy the assertion above.
        self::assertInstanceOf(GoogleOAuthProvider::class, $registry->get('google'));
        self::assertInstanceOf(AppleOAuthProvider::class, $registry->get('apple'));
    }

    /**
     * With Apple's key absent, as `.env.test` ships, Apple is collected but not offered. This also keeps the test above
     * honest: both cannot hold if the key injection did nothing.
     */
    public function testAnUnconfiguredProviderIsCollectedButNotOffered(): void
    {
        $_ENV[self::APPLE_KEY_VAR] = '';

        $registry = $this->registry();

        self::assertSame(['google'], $registry->getConfiguredNames());
        self::assertInstanceOf(GoogleOAuthProvider::class, $registry->get('google'));
    }

    private function registry(): OAuthProviderRegistry
    {
        self::bootKernel();

        $registry = self::getContainer()->get(OAuthProviderRegistry::class);
        self::assertInstanceOf(OAuthProviderRegistry::class, $registry);

        return $registry;
    }
}
