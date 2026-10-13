<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ApiRequestModel;
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

    public function testAnApiTicketIsReadAsAnAnswer(): void
    {
        $request = ApiRequestModel::get('https://pds.example.test/xrpc/x', ['Authorization' => 'Bearer jwt-1']);

        $ticket = RequestTicketModel::api($request, 'account-7');

        self::assertSame(ResponseExchangeKind::Api, $ticket->exchange);
        self::assertSame($request, $ticket->request);
    }

    public function testANamedSlotIgnoresTheHostAndNeverMeetsAHostSlot(): void
    {
        $api = RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), 'example.com');
        $feed = RequestTicketModel::feed(new FetchTicketModel('https://example.com/feed'));

        self::assertSame($api->slotKeyFor('https://example.com/a'), $api->slotKeyFor('https://other.example/b'));
        self::assertNotSame($api->slotKeyFor('https://example.com/a'), $feed->slotKeyFor('https://example.com/a'));
    }

    public function testAnApiTicketCarriesNoFeedTicket(): void
    {
        $this->expectException(\LogicException::class);

        RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), 'account-7')->feedTicket();
    }

    public function testASlotKeyNamesWhetherItIsAHostOrANamedSlot(): void
    {
        $api = RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), 'account-7');
        $feed = RequestTicketModel::feed(new FetchTicketModel('https://www.example.com/feed'));

        self::assertSame('slot:account-7', $api->slotKeyFor('https://example.com/a'));
        self::assertSame('host:example.com', $feed->slotKeyFor('https://www.example.com/feed'));
    }
}
