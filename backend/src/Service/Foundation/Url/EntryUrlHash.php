<?php

declare(strict_types=1);

namespace App\Service\Url;

use App\Service\Url\EntryIdentityUrl\EntryIdentityUrlInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The url_hash of an entry: every writer of it calls here, or copies of one article stop collapsing. */
final readonly class EntryUrlHash
{
    /** @param iterable<EntryIdentityUrlInterface> $identityUrls */
    public function __construct(
        private UrlNormalizer $urlNormalizer,
        #[AutowireIterator('app.entry_identity_url')]
        private iterable $identityUrls,
    ) {
    }

    public function of(string $guid, ?string $url): ?string
    {
        return $this->urlNormalizer->hash($this->identityUrl($guid) ?? $url);
    }

    private function identityUrl(string $guid): ?string
    {
        foreach ($this->identityUrls as $identityUrl) {
            $named = $identityUrl->forGuid($guid);
            if ($named !== null) {
                return $named;
            }
        }

        return null;
    }
}
