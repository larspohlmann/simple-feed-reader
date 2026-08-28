<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds absolute reader deep-links from APP_FRONTEND_URL — the same base
 * AccountMailer uses for verification links, so the digest cannot drift from a
 * value already known to be correct on this host (#636). Deep links are query
 * params, not path segments, so no server rewrite is involved.
 */
final readonly class DigestLinkBuilder
{
    private string $base;

    public function __construct(
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        string $frontendUrl,
    ) {
        $this->base = rtrim($frontendUrl, '/') . '/';
    }

    public function entryUrl(int $entryId): string
    {
        return $this->base . '?entry=' . $entryId;
    }

    public function savedSearchUrl(string $term, bool $wholeWord): string
    {
        $query = $wholeWord ? $term . ' ' : $term;

        return $this->base . '?q=' . rawurlencode($query);
    }
}
