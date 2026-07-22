<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use App\Exception\OAuth\UnknownProviderException;
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
     * The invisibility property, asserted rather than assumed.
     *
     * "Provider is not registered at all" and "provider is registered but this
     * deployment has no credentials for it" must be indistinguishable from
     * outside. If they were not, an unauthenticated stranger could enumerate
     * which integrations this deployment holds keys for by diffing the two
     * responses — which is exactly the sort of thing that tells an attacker
     * where to spend their time.
     *
     * Compared field by field rather than by class, because the problem
     * document ApiExceptionListener renders is built from these five public
     * properties and nothing else. Two exceptions equal across all of them
     * serialise to byte-identical responses.
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
        self::assertSame($absent->type, $unconfigured->type);
        self::assertSame($absent->status, $unconfigured->status);
        self::assertSame($absent->title, $unconfigured->title);
        self::assertSame($absent->detail, $unconfigured->detail);
        self::assertSame($absent->errors, $unconfigured->errors);
        // The message is what a naive log line or a debug handler would print.
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
