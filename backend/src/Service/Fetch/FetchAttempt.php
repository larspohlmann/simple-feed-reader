<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * One feed's position in its redirect chain. Immutable: each hop yields a new
 * attempt, so the permanent-redirect flag can no longer be smuggled back to the
 * caller through a by-reference parameter.
 */
final readonly class FetchAttempt
{
    public const int MAX_REDIRECTS = 5;

    /**
     * Private so a fresh attempt can only be built by `start()`, which seeds the
     * URL from the ticket. A public constructor would need `$url` to default to
     * another promoted property, which PHP cannot express.
     */
    private function __construct(
        public int|string $key,
        public FetchTicket $ticket,
        public string $url,
        public bool $permanentRedirect,
        private int $hop,
        public int $pinnedAddressAttempt = 0,
        public ?ProxyConfig $proxy = null,
    ) {
    }

    public static function start(int|string $key, FetchTicket $ticket, ?ProxyConfig $proxy = null): self
    {
        return new self($key, $ticket, $ticket->url, false, 0, proxy: $proxy);
    }

    public function canFollowRedirect(): bool
    {
        return $this->hop < self::MAX_REDIRECTS;
    }

    public function followedTo(string $url, bool $permanent): self
    {
        return new self(
            $this->key,
            $this->ticket,
            $url,
            $this->permanentRedirect || $permanent,
            $this->hop + 1,
            // A redirect lands on a fresh host, so the address pins start over.
            proxy: $this->proxy,
        );
    }

    /**
     * The same request pinned to the next address family. A family that connects
     * and only then dies (heise's IPv6 from Strato resets at TLS) takes the whole
     * request down with no client-side fallback; re-driving it over the next pin
     * reaches the family that works. The redirect hop count is untouched — this
     * is the same hop over a different route, not a new one.
     */
    public function overNextPinnedAddress(): self
    {
        return new self(
            $this->key,
            $this->ticket,
            $this->url,
            $this->permanentRedirect,
            $this->hop,
            $this->pinnedAddressAttempt + 1,
            $this->proxy,
        );
    }

    public function isProxied(): bool
    {
        return null !== $this->proxy;
    }

    /** The single direct fallback for a proxied attempt: same URL, proxy dropped. */
    public function withoutProxy(): self
    {
        return new self($this->key, $this->ticket, $this->url, $this->permanentRedirect, $this->hop);
    }
}
