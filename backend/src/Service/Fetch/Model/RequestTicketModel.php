<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Support\HostKey;

/** One request the concurrent engine sends: what goes on the wire, and which exchange reads the answer. */
final readonly class RequestTicketModel
{
    private function __construct(
        public ResponseExchangeKind $exchange,
        public ApiRequestModel $request,
        private ?string $slotName,
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket): self
    {
        return new self(
            ResponseExchangeKind::Feed,
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            null,
            $ticket,
        );
    }

    /** Any status comes back as the answer; tickets sharing a slot name share its capacity, whatever their host. */
    public static function api(ApiRequestModel $request, string $slotName): self
    {
        return new self(ResponseExchangeKind::Api, $request, $slotName, null);
    }

    public function slotKeyFor(string $url): string
    {
        return null === $this->slotName ? 'host:' . HostKey::forUrl($url) : 'slot:' . $this->slotName;
    }

    public function feedTicket(): FetchTicketModel
    {
        return $this->feedTicket ?? throw new \LogicException('Only a feed ticket carries cache validators.');
    }

    /** @return array<string, string> */
    private static function validatorHeaders(FetchTicketModel $ticket): array
    {
        $headers = [];
        if (null !== $ticket->etag) {
            $headers['If-None-Match'] = $ticket->etag;
        }
        if (null !== $ticket->lastModified) {
            $headers['If-Modified-Since'] = $ticket->lastModified;
        }

        return $headers;
    }
}
