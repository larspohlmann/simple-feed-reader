<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Exception\OAuth\UnknownProviderException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the `{provider}` path segment to an implementation.
 *
 * Providers are collected by tagged-iterator autowiring, so a new one is a new
 * class implementing OAuthProviderInterface and nothing else — no registration
 * list to update and forget.
 *
 * The tag is applied by the `_instanceof` block in `config/services.yaml`, and it
 * is load-bearing. `autoconfigure: true` does NOT register a tag named after a
 * plain application interface, so `#[AutowireIterator(OAuthProviderInterface::class)]`
 * instead yields an empty iterator — verified by booting the kernel and reading
 * this property, which came back `[]`. It fails silently: the registry builds,
 * every lookup throws UnknownProviderException, and the deployment looks like a
 * correctly wired one with no credentials. Unit tests cannot see it either, since
 * they build the registry by hand. OAuthProviderWiringTest exists to catch it.
 */
final readonly class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderInterface> */
    private array $providers;

    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.oauth_provider')] iterable $providers,
    ) {
        $byName = [];
        foreach ($providers as $provider) {
            $byName[$provider->getName()] = $provider;
        }

        $this->providers = $byName;
    }

    /**
     * An unconfigured provider is treated exactly like a nonexistent one. The
     * distinction is real to an operator and meaningless to a visitor, and
     * reporting it would tell an unauthenticated stranger which integrations this
     * deployment has credentials for.
     *
     * Both cases throw the same exception with the same message, so they are
     * indistinguishable in the response, a log line, and a debug handler — not
     * merely in the status code. No timing difference to close: both paths are an
     * array lookup, and isConfigured() is a string comparison over values in
     * memory, never touching the network or disk.
     */
    public function get(string $name): OAuthProviderInterface
    {
        $provider = $this->providers[$name] ?? null;

        if (null === $provider || !$provider->isConfigured()) {
            throw new UnknownProviderException();
        }

        return $provider;
    }

    /**
     * Feeds the SPA's list of sign-in buttons, so the frontend never renders a
     * provider this deployment cannot complete.
     *
     * Order is collection order, deliberately not sorted: these become buttons,
     * and a list that reordered itself between container rebuilds would move them
     * under the user's fingers. Collection order is fixed by the order the
     * container finds the classes in, stable for a given build.
     *
     * @return list<string>
     */
    public function getConfiguredNames(): array
    {
        $names = [];
        foreach ($this->providers as $name => $provider) {
            if ($provider->isConfigured()) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
