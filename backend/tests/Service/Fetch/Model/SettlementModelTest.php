<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettlementModelTest extends TestCase
{
    public function testAnOutcomeCarriesItsValueAndAsksForNoFollowUp(): void
    {
        $outcome = FetchOutcomeModel::withheld();

        $settlement = SettlementModel::outcome($outcome);

        self::assertFalse($settlement->isFollowUp());
        self::assertSame($outcome, $settlement->settledOutcome());
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'an empty list' => [[]];
    }

    #[DataProvider('emptyValues')]
    public function testAnEmptyValueIsStillAFinishedValue(mixed $value): void
    {
        $settlement = SettlementModel::outcome($value);

        self::assertFalse($settlement->isFollowUp());
        self::assertSame($value, $settlement->settledOutcome());
    }

    public function testAFollowUpCarriesItsTicket(): void
    {
        $ticket = FeedTickets::of('https://example.com/feed');

        $settlement = SettlementModel::followUp($ticket);

        self::assertTrue($settlement->isFollowUp());
        self::assertSame($ticket, $settlement->followUpTicket());
    }

    public function testAFollowUpCarriesNoOutcomeYet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A follow-up carries no outcome yet.');

        SettlementModel::followUp(FeedTickets::of('https://example.com/feed'))->settledOutcome();
    }

    public function testAFinishedValueAsksForNoFollowUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A finished value asks for no follow-up.');

        SettlementModel::outcome(1)->followUpTicket();
    }

    public function testMappingChangesAFinishedValue(): void
    {
        $mapped = SettlementModel::outcome(1)->mapOutcome(static fn (int $value): int => $value + 1);

        self::assertSame(2, $mapped->settledOutcome());
    }

    public function testMappingKeepsAFollowUpsTicket(): void
    {
        $ticket = FeedTickets::of('https://example.com/feed');

        $mapped = SettlementModel::followUp($ticket)->mapOutcome(static fn (mixed $value): mixed => $value);

        self::assertSame($ticket, $mapped->followUpTicket());
    }
}
