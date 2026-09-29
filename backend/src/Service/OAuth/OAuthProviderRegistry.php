<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Service\OAuth\Exception\UnknownProviderException;
use App\Service\OAuth\OAuthProvider\OAuthProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the `{provider}` path segment. Providers arrive through the `app.oauth_provider` tag from services.yaml's
 * `_instanceof`: an `#[AutowireIterator]` on the interface collects nothing, silently (OAuthProviderWiringTest).
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
     * An unconfigured provider throws exactly like an unknown one, so a stranger cannot learn which integrations this
     * deployment holds credentials for.
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
     * Collection order, deliberately unsorted: the SPA renders these as buttons, which must not move between builds.
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
