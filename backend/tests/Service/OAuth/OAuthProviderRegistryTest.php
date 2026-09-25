<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Http\Problem\OAuthProblems;
use App\Service\OAuth\Exception\UnknownProviderException;
use App\Service\OAuth\OAuthProviderInterface;
use App\Service\OAuth\OAuthProviderRegistry;
use PHPUnit\Framework\TestCase;

final class OAuthProviderRegistryTest extends TestCase
{
    public function testItReturnsAConfiguredProvider(): void
    {
        $registry = new OAuthProviderRegistry([$this->provider('google', true)]);

        self::assertSame('google', $registry->get('google')->getName());
    }

    public function testAnUnconfiguredProviderIsInvisible(): void
    {
        // Not an error state — a deployment that has no Apple credentials
        // simply does not offer Apple, and must not redirect anyone to a
        // consent screen that will fail.
        $registry = new OAuthProviderRegistry([$this->provider('apple', false)]);

        $this->expectException(UnknownProviderException::class);
        $registry->get('apple');
    }

    public function testAnUnregisteredNameThrows(): void
    {
        $registry = new OAuthProviderRegistry([$this->provider('google', true)]);

        $this->expectException(UnknownProviderException::class);
        $registry->get('facebook');
    }

    /**
     * An unconfigured provider must be indistinguishable from an absent one, or a stranger could diff the two
     * responses to learn which integrations this deployment holds keys for.
     */
    public function testAnUnconfiguredProviderIsIndistinguishableFromAnAbsentOne(): void
    {
        $registry = new OAuthProviderRegistry([$this->provider('apple', false)]);

        $unconfigured = null;
        $absent = null;

        try {
            $registry->get('apple');
        } catch (UnknownProviderException $e) {
            $unconfigured = $e;
        }

        try {
            $registry->get('facebook');
        } catch (UnknownProviderException $e) {
            $absent = $e;
        }

        self::assertInstanceOf(UnknownProviderException::class, $unconfigured);
        self::assertInstanceOf(UnknownProviderException::class, $absent);

        self::assertSame($absent::class, $unconfigured::class);
        self::assertEquals((new OAuthProblems())->resolve($absent), (new OAuthProblems())->resolve($unconfigured));
        self::assertSame($absent->getMessage(), $unconfigured->getMessage());
    }

    public function testItListsOnlyConfiguredProviderNames(): void
    {
        $registry = new OAuthProviderRegistry([
            $this->provider('google', true),
            $this->provider('apple', false),
        ]);

        self::assertSame(['google'], $registry->getConfiguredNames());
    }

    /**
     * The list is a `list<string>`, not a map, and it comes out in the order
     * the providers were collected — NOT sorted. Pinned here because the
     * frontend renders sign-in buttons straight from this array, and a list
     * whose order drifts between deployments or between container rebuilds
     * would shuffle the buttons under people's fingers.
     */
    public function testTheOrderFollowsCollectionOrderAndIsNotSorted(): void
    {
        $registry = new OAuthProviderRegistry([
            $this->provider('google', true),
            $this->provider('apple', true),
        ]);

        self::assertSame(['google', 'apple'], $registry->getConfiguredNames());
        self::assertSame([0, 1], array_keys($registry->getConfiguredNames()));
    }

    private function provider(string $name, bool $configured): OAuthProviderInterface
    {
        return new class ($name, $configured) implements OAuthProviderInterface {
            public function __construct(private string $name, private bool $configured)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function getAuthorizationUrl(string $state, string $nonce, string $codeChallenge): string
            {
                return 'https://provider.test/authorize';
            }

            public function exchangeCode(string $code, string $codeVerifier, string $nonce): OAuthIdentity
            {
                return new OAuthIdentity($this->name, 'sub', null, false);
            }
        };
    }
}
