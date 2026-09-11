<?php

declare(strict_types=1);

namespace App\Service\ClientError;

use App\Dto\ClientError\ClientErrorItem;

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

    public function scrub(ClientErrorItem $item): ClientErrorItem
    {
        return new ClientErrorItem(
            message: $this->redact($item->message),
            stack: null === $item->stack ? null : $this->redact($item->stack),
            kind: $item->kind,
            url: $this->stripQueryAndFragment($item->url),
            route: $item->route,
            buildVersion: $item->buildVersion,
            userAgent: $item->userAgent,
            at: $item->at,
        );
    }

    private function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    private function stripQueryAndFragment(?string $url): ?string
    {
        if (null === $url || '' === $url) {
            return $url;
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['path'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';

        return $scheme . $host . $parts['path'];
    }
}
