<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;

/** One request the concurrent engine sends: what goes on the wire, which exchange reads the answer, what it holds. */
final readonly class RequestTicketModel
{
    public ResponseExchangeKind $exchange;

    /** @param non-empty-list<RequestSlotModelInterface> $slots */
    private function __construct(
        public ApiRequestModel $request,
        public array $slots,
        private ?FetchTicketModel $feedTicket,
    ) {
        $this->exchange = null === $feedTicket ? ResponseExchangeKind::Api : ResponseExchangeKind::Feed;
    }

    public static function feed(FetchTicketModel $ticket, int $hostCapacity): self
    {
        return new self(
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            [new HostSlotModel($hostCapacity)],
            $ticket,
        );
    }

    /**
     * Any status comes back as the answer; the ticket goes out only while every slot it names has room.
     *
     * @param list<RequestSlotModelInterface> $slots
     *
     * @throws \InvalidArgumentException when no slot is named, which would leave the ticket uncapped
     */
    public static function api(ApiRequestModel $request, array $slots): self
    {
        if ([] === $slots) {
            throw new \InvalidArgumentException('An API ticket must name at least one slot.');
        }

        return new self($request, $slots, null);
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
