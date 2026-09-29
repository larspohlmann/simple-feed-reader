<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

use App\Service\Ai\Exception\ProviderUnreachableException;

/**
 * An endpoint and its key. The base URL is the full root as entered, `/v1` included; callers append only the path.
 * It deliberately skips UrlGuard so a local provider works: never copy this for another outbound call.
 * Accepted risk: docs/security.md#ai-provider-endpoints.
 */
final readonly class ProviderCredentialsModel
{
    private function __construct(
        public string $baseUrl,
        public string $apiKey,
    ) {
    }

    /**
     * What the account typed. The constructor is private so this path — the
     * only one that ever sees untrusted input — cannot be bypassed: the checks
     * below are the entire validation this URL gets.
     */
    public static function fromAccountInput(string $baseUrl, string $apiKey): self
    {
        return new self(self::normalizeBaseUrl($baseUrl), trim($apiKey));
    }

    /** An already-validated stored base URL and a freshly opened key; re-validating could only break a row. */
    public static function fromStoredConfiguration(string $baseUrl, string $apiKey): self
    {
        return new self($baseUrl, $apiKey);
    }

    /**
     * The auth headers this endpoint needs, which for a keyless local server is
     * none at all. An empty key must not become `Bearer ` — an empty credential
     * is malformed, and a gateway is entitled to refuse it.
     *
     * @return array<string, string>
     */
    public function authorizationHeaders(): array
    {
        if ('' === $this->apiKey) {
            return [];
        }

        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * Trims the value and removes trailing slashes, so `…/v1` and `…/v1/`
     * produce one stored form and one request URL.
     */
    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $parts = parse_url($trimmed);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new ProviderUnreachableException('That is not a complete address.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ProviderUnreachableException(
                'Remove the username and password from the address; the API key is sent separately.',
            );
        }

        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ProviderUnreachableException('The address must start with http:// or https://.');
        }

        // A query or fragment would land after the appended path; refused here, not later as "unreachable".
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProviderUnreachableException('Remove the query string or fragment from the address.');
        }

        return $trimmed;
    }
}
