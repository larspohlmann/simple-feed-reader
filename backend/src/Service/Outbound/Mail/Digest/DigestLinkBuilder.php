<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Settings\PublicBaseUrl\PublicBaseUrlInterface;

/**
 * Builds absolute reader deep links from the public base URL every account email uses, so a digest link cannot point
 * where a verification link would not. Query params, not path segments: no server rewrite is involved.
 */
final readonly class DigestLinkBuilder
{
    public function __construct(private PublicBaseUrlInterface $publicBaseUrl)
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

    public function savedSearchesUrl(): string
    {
        return $this->base() . '?view=saved-searches';
    }

    public function settingsEmailUrl(): string
    {
        return $this->base() . 'settings/email';
    }

    public function base(): string
    {
        return rtrim($this->publicBaseUrl->get(), '/') . '/';
    }
}
