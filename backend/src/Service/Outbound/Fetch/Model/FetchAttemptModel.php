<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Model;

/** One ticket's attempt on the wire: its redirect hop, its address family and its route. */
final readonly class FetchAttemptModel
{
    public const int MAX_REDIRECTS = 5;

    /** Private: only `start()` seeds `$url` from the ticket, a default a promoted parameter cannot express. */
    private function __construct(
        public int|string $key,
        public RequestTicketModel $ticket,
        public string $url,
        public bool $permanentRedirect,
        private int $hop,
        public int $pinnedAddressAttempt = 0,
        public ?ProxyConfigModel $proxy = null,
        private bool $firstSend = false,
    ) {
    }

    public static function start(int|string $key, RequestTicketModel $ticket, ?ProxyConfigModel $proxy = null): self
    {
        return new self($key, $ticket, $ticket->request->url, false, 0, proxy: $proxy, firstSend: true);
    }

    public function canFollowRedirect(): bool
    {
        return $this->hop < self::MAX_REDIRECTS;
    }

    #[\NoDiscard]
    public function followedTo(string $url, bool $permanent): self
    {
        return clone($this, [
            'url' => $url,
            'permanentRedirect' => $this->permanentRedirect || $permanent,
            'hop' => $this->hop + 1,
            // A redirect lands on a fresh host, so the address pins start over.
            'pinnedAddressAttempt' => 0,
            'firstSend' => false,
        ]);
    }

    /**
     * The same hop re-driven over the next address family, for a family that connects and only then dies (a reset at
     * TLS), which the client does not fall back from. The redirect hop count stays as it is.
     */
    #[\NoDiscard]
    public function overNextPinnedAddress(): self
    {
        return clone($this, ['pinnedAddressAttempt' => $this->pinnedAddressAttempt + 1, 'firstSend' => false]);
    }

    public function isFirstSend(): bool
    {
        return $this->firstSend;
    }

    public function isProxied(): bool
    {
        return null !== $this->proxy;
    }

    #[\NoDiscard]
    public function withoutProxy(): self
    {
        return clone($this, ['pinnedAddressAttempt' => 0, 'proxy' => null, 'firstSend' => false]);
    }
}
