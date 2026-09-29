<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** One feed's position in its redirect chain. */
final readonly class FetchAttemptModel
{
    public const int MAX_REDIRECTS = 5;

    /** Private: only `start()` seeds `$url` from the ticket, a default a promoted parameter cannot express. */
    private function __construct(
        public int|string $key,
        public FetchTicketModel $ticket,
        public string $url,
        public bool $permanentRedirect,
        private int $hop,
        public int $pinnedAddressAttempt = 0,
        public ?ProxyConfigModel $proxy = null,
    ) {
    }

    public static function start(int|string $key, FetchTicketModel $ticket, ?ProxyConfigModel $proxy = null): self
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
     * The same hop re-driven over the next address family, for a family that connects and only then dies (a reset at
     * TLS), which the client does not fall back from. The redirect hop count stays as it is.
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

    public function withoutProxy(): self
    {
        return new self($this->key, $this->ticket, $this->url, $this->permanentRedirect, $this->hop);
    }
}
