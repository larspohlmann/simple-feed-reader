<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/** One request the concurrent engine sends: what goes on the wire, which exchange reads the answer, what it holds. */
final readonly class RequestTicketModel
{
    /** @param non-empty-list<RequestSlotModel> $slots */
    private function __construct(
        public ResponseExchangeKind $exchange,
        public ApiRequestModel $request,
        public array $slots,
        private ?FetchTicketModel $feedTicket,
    ) {
    }

    public static function feed(FetchTicketModel $ticket, int $hostCapacity): self
    {
        return new self(
            ResponseExchangeKind::Feed,
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            [RequestSlotModel::perHost($hostCapacity)],
            $ticket,
        );
    }

    /**
     * Any status comes back as the answer; the ticket goes out only while every slot it names has room.
     *
     * @param list<RequestSlotModel> $slots
     *
     * @throws \InvalidArgumentException when no slot is named, which would leave the ticket uncapped
     */
    public static function api(ApiRequestModel $request, array $slots): self
    {
        if ([] === $slots) {
            throw new \InvalidArgumentException('An API ticket must name at least one slot.');
        }

        return new self(ResponseExchangeKind::Api, $request, $slots, null);
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
