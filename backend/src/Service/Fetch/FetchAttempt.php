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
    ) {
    }

    public static function start(int|string $key, FetchTicket $ticket): self
    {
        return new self($key, $ticket, $ticket->url, false, 0);
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
        );
    }
}
