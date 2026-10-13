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
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket): self
    {
        return new self(
            ResponseExchangeKind::Feed,
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            $ticket,
        );
    }

    public function slotKeyFor(string $url): string
    {
        return HostKey::forUrl($url);
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
