<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Exception\ProviderUnreachableException;

/**
 * An endpoint and the key that opens it, ready to use. The base URL is the
 * full OpenAI-compatible root the account entered, including any `/v1` — the
 * catalog appends `/models` and nothing else, because the path prefix differs
 * between providers and guessing it would break the ones that do not use it.
 *
 * NOTE ON SSRF: this URL deliberately does NOT pass through UrlGuard, so a
 * local provider works. That is a recorded exception to the standing boundary,
 * decided for #305; the reasoning and the accepted risk are in the design
 * spec. Do not copy this class as a template for any other outbound call.
 */
final readonly class ProviderCredentials
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

    /**
     * A base URL that went through fromAccountInput() before it was stored, and
     * a key the cipher just opened. Both are already in their normalised form,
     * so re-validating them here would only invent a way for a stored row to
     * stop working.
     */
    public static function fromStoredConfiguration(string $baseUrl, string $apiKey): self
    {
        return new self($baseUrl, $apiKey);
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

        // A query or fragment has nowhere to go once /models is appended — it would
        // land after the appended path instead of before it, producing a URL the
        // provider was never meant to receive. Reject it here, the one place this
        // input is validated, instead of failing later as a confusing "unreachable".
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProviderUnreachableException('Remove the query string or fragment from the address.');
        }

        return $trimmed;
    }
}
