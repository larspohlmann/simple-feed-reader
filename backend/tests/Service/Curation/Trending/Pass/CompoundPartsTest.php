<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\Pass\CompoundParts;
use PHPUnit\Framework\TestCase;

final class CompoundPartsTest extends TestCase
{
    private const int POLICE_OPERATION = 1;
    private const int POLICE = 2;
    private const int OPERATION = 3;
    private const int FIRE_OPERATION = 4;
    private const int FIRE_BRIGADE = 5;
    private const int STATION = 6;

    private CompoundParts $compoundParts;

    protected function setUp(): void
    {
        $this->compoundParts = new CompoundParts();
        $this->compoundParts->addCompound(self::POLICE_OPERATION, [self::POLICE, self::OPERATION]);
        $this->compoundParts->addCompound(self::FIRE_OPERATION, [self::FIRE_BRIGADE, self::OPERATION]);
    }

    public function testAStemNoEntryHoldsAsAPartOnlyChangesNoLink(): void
    {
        $this->addPoliceOperation(10);
        $this->addPoliceOperation(20);

        self::assertSame([], $this->linksOf(self::STATION, 20));
    }

    public function testAPartOfACompoundBothEntriesHoldLinksNothing(): void
    {
        $this->addPoliceOperation(10);
        $this->addPoliceOperation(20);

        self::assertSame([10 => []], $this->linksOf(self::POLICE, 20));
    }

    public function testAPartOfTheEarlierEntrysSharedCompoundLinksNothingEither(): void
    {
        $this->addPoliceOperation(10);
        $this->addEntry(
            20,
            [self::POLICE_OPERATION, self::POLICE, self::OPERATION],
            [self::POLICE_OPERATION, self::POLICE],
        );

        self::assertSame([10 => []], $this->linksOf(self::POLICE, 20));
    }

    public function testAPartOfASharedCompoundLinksNothingWhileAnotherCompoundHoldsItElsewhere(): void
    {
        $this->addPoliceOperation(10);
        $this->addPoliceOperation(20);

        self::assertSame([10 => []], $this->linksOf(self::OPERATION, 20), 'fire operation holds the part too');
    }

    public function testAWordBothEntriesHoldLinksAsAWordThoughAnotherEntryHoldsItAsAPart(): void
    {
        $this->addPoliceOperation(5);
        $policeAndPoliceOperation = [self::POLICE_OPERATION, self::POLICE, self::OPERATION];
        $this->addEntry(10, $policeAndPoliceOperation, [self::POLICE_OPERATION, self::POLICE]);
        $this->addEntry(20, $policeAndPoliceOperation, [self::POLICE_OPERATION, self::POLICE]);

        self::assertSame([], $this->linksOf(self::POLICE, 20), 'the link is a plain word link');
    }

    public function testEveryHolderAPartLinksStays(): void
    {
        $this->addEntry(5, [self::POLICE], [self::POLICE]);
        $this->addEntry(10, [self::POLICE], [self::POLICE]);
        $this->addPoliceOperation(20);

        self::assertSame(
            [5 => [[self::POLICE_OPERATION => 1.0], []], 10 => [[self::POLICE_OPERATION => 1.0], []]],
            $this->compoundParts->buildPartLinks(self::POLICE, 20, [5 => 1.0, 10 => 0.5]),
        );
    }

    public function testAPartLinksTheBareWordThroughTheEarlierEntrysCompound(): void
    {
        $this->addPoliceOperation(10);
        $this->addEntry(20, [self::POLICE], [self::POLICE]);

        self::assertSame([10 => [[], [self::POLICE_OPERATION => 1.0]]], $this->linksOf(self::POLICE, 20));
    }

    public function testAPartLinksTwoDifferentCompoundsThroughBoth(): void
    {
        $this->addPoliceOperation(10);
        $this->addEntry(20, [self::FIRE_OPERATION, self::FIRE_BRIGADE, self::OPERATION], [self::FIRE_OPERATION]);

        self::assertSame(
            [10 => [[self::FIRE_OPERATION => 1.0], [self::POLICE_OPERATION => 1.0]]],
            $this->linksOf(self::OPERATION, 20),
        );
    }

    public function testAPartComesThroughACompoundAtTheCompoundsPlaceWeight(): void
    {
        $this->compoundParts->addEntry(10, [self::POLICE]);
        $this->compoundParts->holdCompoundsAmong(10, [self::POLICE_OPERATION, self::POLICE], 0.5);
        $this->addEntry(20, [self::POLICE], [self::POLICE]);

        self::assertSame([10 => [[], [self::POLICE_OPERATION => 0.5]]], $this->linksOf(self::POLICE, 20));
    }

    public function testAPartLinksNothingWhenOneEntryHoldsItOnlyThroughASharedCompound(): void
    {
        $this->addEntry(
            10,
            [self::POLICE_OPERATION, self::FIRE_OPERATION, self::POLICE, self::OPERATION, self::FIRE_BRIGADE],
            [self::POLICE_OPERATION, self::FIRE_OPERATION],
        );
        $this->addPoliceOperation(20);

        self::assertSame([10 => []], $this->linksOf(self::OPERATION, 20));
    }

    public function testAnEntryHoldsAsAPartOnlyTheStemsItIsGivenAsParts(): void
    {
        $this->compoundParts->addEntry(10, [self::OPERATION]);

        self::assertTrue($this->compoundParts->isOnlyAPartIn(10, self::OPERATION));
        self::assertFalse($this->compoundParts->isOnlyAPartIn(10, self::POLICE));
        self::assertFalse($this->compoundParts->isOnlyAPartIn(10, self::POLICE_OPERATION));
    }

    public function testAnEntryAddedBeforeItsWordWasFoundACompoundHoldsItOnceTold(): void
    {
        $this->compoundParts = new CompoundParts();
        $policeOperationAndPolice = [self::POLICE_OPERATION, self::POLICE];
        $this->addEntry(10, $policeOperationAndPolice, $policeOperationAndPolice);
        $this->compoundParts->addCompound(self::POLICE_OPERATION, [self::POLICE, self::OPERATION]);
        $this->compoundParts->holdCompoundIn(self::POLICE_OPERATION, [10], 1.0);
        $this->addPoliceOperation(20);

        self::assertSame([10 => []], $this->linksOf(self::POLICE, 20));
    }

    public function testAnEntryHoldsNoCompoundItHoldsOnlyAsAPart(): void
    {
        $this->compoundParts->addCompound(self::STATION, [self::POLICE_OPERATION]);
        $this->addEntry(10, [self::STATION, self::POLICE_OPERATION], [self::STATION]);
        $this->compoundParts->holdCompoundIn(self::POLICE_OPERATION, [10], 1.0);
        $this->addPoliceOperation(20);

        self::assertSame([10 => [[self::POLICE_OPERATION => 1.0], []]], $this->linksOf(self::POLICE, 20));
    }

    public function testAStemIsACompoundOnceAWordGivesItParts(): void
    {
        self::assertTrue($this->compoundParts->isCompound(self::POLICE_OPERATION));
        self::assertFalse($this->compoundParts->isCompound(self::STATION));
    }

    /**
     * @param list<int> $stemIds
     * @param list<int> $wordStemIds
     */
    private function addEntry(int $entryId, array $stemIds, array $wordStemIds): void
    {
        $this->compoundParts->addEntry($entryId, array_values(array_diff($stemIds, $wordStemIds)));
        $this->compoundParts->holdCompoundsAmong($entryId, $stemIds, 1.0);
    }

    private function addPoliceOperation(int $entryId): void
    {
        $this->addEntry($entryId, [self::POLICE_OPERATION, self::POLICE, self::OPERATION], [self::POLICE_OPERATION]);
    }

    /** @return array<int, array{}|array{array<int, float>, array<int, float>}> */
    private function linksOf(int $stemId, int $entryId): array
    {
        return $this->compoundParts->buildPartLinks($stemId, $entryId, [10 => 1.0]);
    }
}
