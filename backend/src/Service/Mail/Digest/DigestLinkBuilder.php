<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Settings\PublicBaseUrl;

/**
 * Builds absolute reader deep-links from the configured public base URL — the
 * same value every account email uses, so a digest link cannot point somewhere
 * a verification link would not (#636). Deep links are query params, not path
 * segments, so no server rewrite is involved.
 */
final readonly class DigestLinkBuilder
{
    public function __construct(private PublicBaseUrl $publicBaseUrl)
    {
    }

    public function entryUrl(int $entryId): string
    {
        return $this->base() . '?entry=' . $entryId;
    }

    public function savedSearchUrl(string $term, bool $wholeWord): string
    {
        $query = $wholeWord ? $term . ' ' : $term;

        return $this->base() . '?q=' . rawurlencode($query);
    }

    private function base(): string
    {
        return rtrim($this->publicBaseUrl->get(), '/') . '/';
    }
}
