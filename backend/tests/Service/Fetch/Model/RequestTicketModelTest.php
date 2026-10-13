<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use PHPUnit\Framework\TestCase;

final class RequestTicketModelTest extends TestCase
{
    private const string FEED_URL = 'https://example.com/feed';
    private const string LAST_MODIFIED = 'Mon, 20 Jul 2026 08:30:00 GMT';

    public function testAFeedTicketIsAGetCarryingOnlyTheValidatorItHas(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel(self::FEED_URL, '"v1"'));

        self::assertSame(ResponseExchangeKind::Feed, $ticket->exchange);
        self::assertSame('GET', $ticket->request->method);
        self::assertSame(self::FEED_URL, $ticket->request->url);
        self::assertSame(['If-None-Match' => '"v1"'], $ticket->request->headers);
        self::assertNull($ticket->request->json);
    }

    public function testAFeedTicketSendsBothValidatorsWhenItHasBoth(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel(self::FEED_URL, '"v1"', self::LAST_MODIFIED));

        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => self::LAST_MODIFIED],
            $ticket->request->headers,
        );
    }

    public function testAnUnconditionalFeedTicketSendsNoValidator(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel(self::FEED_URL));

        self::assertSame([], $ticket->request->headers);
    }

    public function testAFeedTicketKeepsItsFeedTicketForTheNotModifiedAnswer(): void
    {
        $feedTicket = new FetchTicketModel(self::FEED_URL, '"v1"');

        self::assertSame($feedTicket, RequestTicketModel::feed($feedTicket)->feedTicket());
    }

    public function testAFeedTicketsSlotIsTheHostOfTheUrlItHasReached(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://www.example.com/feed'));

        $apexSlot = $ticket->slotKeyFor('https://example.com/a');

        self::assertSame($apexSlot, $ticket->slotKeyFor('https://www.example.com:443/b'));
        self::assertNotSame($apexSlot, $ticket->slotKeyFor('https://other.example.com/a'));
    }
}
