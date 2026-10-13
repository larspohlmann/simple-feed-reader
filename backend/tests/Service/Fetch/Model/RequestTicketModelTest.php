<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestSlotModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use PHPUnit\Framework\TestCase;

final class RequestTicketModelTest extends TestCase
{
    private const string FEED_URL = 'https://example.com/feed';
    private const string LAST_MODIFIED = 'Mon, 20 Jul 2026 08:30:00 GMT';

    public function testAFeedTicketIsAGetCarryingOnlyTheValidatorItHas(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel(self::FEED_URL, '"v1"'), 2);

        self::assertSame(ResponseExchangeKind::Feed, $ticket->exchange);
        self::assertSame('GET', $ticket->request->method);
        self::assertSame(self::FEED_URL, $ticket->request->url);
        self::assertSame(['If-None-Match' => '"v1"'], $ticket->request->headers);
        self::assertNull($ticket->request->json);
    }

    public function testAFeedTicketSendsBothValidatorsWhenItHasBoth(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel(self::FEED_URL, '"v1"', self::LAST_MODIFIED), 2);

        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => self::LAST_MODIFIED],
            $ticket->request->headers,
        );
    }

    public function testAnUnconditionalFeedTicketSendsNoValidator(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel(self::FEED_URL), 2);

        self::assertSame([], $ticket->request->headers);
    }

    public function testAFeedTicketKeepsItsFeedTicketForTheNotModifiedAnswer(): void
    {
        $feedTicket = new FetchTicketModel(self::FEED_URL, '"v1"');

        self::assertSame($feedTicket, RequestTicketModel::feed($feedTicket, 2)->feedTicket());
    }

    public function testAnApiTicketIsReadAsAnAnswer(): void
    {
        $request = ApiRequestModel::get('https://pds.example.test/xrpc/x', ['Authorization' => 'Bearer jwt-1']);

        $ticket = RequestTicketModel::api($request, [RequestSlotModel::named('account-7', 3)]);

        self::assertSame(ResponseExchangeKind::Api, $ticket->exchange);
        self::assertSame($request, $ticket->request);
    }

    public function testAnApiTicketCarriesNoFeedTicket(): void
    {
        $this->expectException(\LogicException::class);

        $slots = [RequestSlotModel::named('account-7', 3)];

        RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), $slots)->feedTicket();
    }

    public function testAFeedTicketHoldsOneSlotOnTheHostItsAttemptHasReached(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://www.example.com/feed'), 2);

        self::assertCount(1, $ticket->slots);
        self::assertSame(2, $ticket->slots[0]->capacity);
        self::assertSame('host:example.com', $ticket->slots[0]->keyFor('https://www.example.com:443/b'));
        self::assertSame('host:other.example.com', $ticket->slots[0]->keyFor('https://other.example.com/a'));
    }

    public function testAnApiTicketHoldsEverySlotItNamesInOrder(): void
    {
        $account = RequestSlotModel::named('social-account:7', 3);
        $server = RequestSlotModel::named('social-host:example.com', 4);

        $ticket = RequestTicketModel::api(ApiRequestModel::get('https://example.com/a'), [$account, $server]);

        self::assertSame([$account, $server], $ticket->slots);
    }
}
