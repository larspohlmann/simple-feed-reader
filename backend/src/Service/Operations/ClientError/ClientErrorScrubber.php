<?php

declare(strict_types=1);

namespace App\Service\ClientError;

use App\Service\ClientError\Model\ClientErrorModel;

/**
 * Redacts secrets before a client error reaches Loki. The patterns are a
 * small documented set derived from what browser stacks actually leak, not
 * an attempt at exhaustive DLP.
 */
final readonly class ClientErrorScrubber
{
    /** @var array<string, string> */
    private const array PATTERNS = [
        // "Authorization: Bearer <token>" — matched before the JWT pattern below so the
        // header keeps its "Bearer " context instead of being swallowed as a bare JWT.
        '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [REDACTED]',
        // A three-segment base64url JWT (header.payload.signature) on its own.
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => '[REDACTED_JWT]',
        // Email addresses.
        '/[\w.+-]+@[\w-]+\.[\w.-]+/' => '[REDACTED_EMAIL]',
        // Values of secret-shaped query parameters; low-risk keys like "page" stay readable.
        '/([?&](?:token|api[_-]?key|key|secret|password|access[_-]?token)=)[^&\s\'"]+/i' => '$1[REDACTED]',
        // A long hex run (session ids, hashes, hex-encoded keys).
        '/\b[0-9a-fA-F]{32,}\b/' => '[REDACTED_HEX]',
    ];

    public function scrub(ClientErrorModel $clientError): ClientErrorModel
    {
        return new ClientErrorModel(
            message: $this->redact($clientError->message),
            stack: null === $clientError->stack ? null : $this->redact($clientError->stack),
            kind: $clientError->kind,
            url: $this->stripQueryAndFragment($clientError->url),
            route: $this->stripQueryAndFragment($clientError->route),
            buildVersion: $clientError->buildVersion,
            userAgent: $clientError->userAgent,
            at: $clientError->at,
        );
    }

    private function redact(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    private function stripQueryAndFragment(?string $url): ?string
    {
        if (null === $url || '' === $url) {
            return $url;
        }

        $parts = parse_url($url);
        if (false === $parts) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        return $scheme . $host . $path;
    }
}
