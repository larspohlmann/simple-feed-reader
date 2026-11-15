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

    public function testAStemNoCompoundHoldsLinksEveryHolder(): void
    {
        $this->addPoliceOperation(10);
        $this->addPoliceOperation(20);

        self::assertSame([10 => 1.0], $this->holdersOf(self::STATION, 20));
    }

    public function testAPartOfACompoundBothEntriesHoldLinksNothing(): void
    {
        $this->addPoliceOperation(10);
        $this->addPoliceOperation(20);

        self::assertSame([], $this->holdersOf(self::POLICE, 20));
    }

    public function testAPartOfTheEarlierEntrysSharedCompoundLinksNothingEither(): void
    {
        $this->addPoliceOperation(10);
        $this->compoundParts->addEntry(
            20,
            [self::POLICE_OPERATION, self::POLICE, self::OPERATION],
            [self::POLICE_OPERATION, self::POLICE],
        );

        self::assertSame([], $this->holdersOf(self::POLICE, 20));
    }

    public function testAPartOfASharedCompoundLinksNothingWhileAnotherCompoundHoldsItElsewhere(): void
    {
        $this->addPoliceOperation(10);
        $this->addPoliceOperation(20);

        self::assertSame([], $this->holdersOf(self::OPERATION, 20), 'fire operation holds the part too');
    }

    public function testAWordBothEntriesHoldStillLinksThoughTheirCompoundHoldsItAsAPart(): void
    {
        $this->addPoliceOperation(5);
        $policeAndPoliceOperation = [self::POLICE_OPERATION, self::POLICE, self::OPERATION];
        $this->compoundParts->addEntry(10, $policeAndPoliceOperation, [self::POLICE_OPERATION, self::POLICE]);
        $this->compoundParts->addEntry(20, $policeAndPoliceOperation, [self::POLICE_OPERATION, self::POLICE]);

        self::assertSame([10 => 1.0], $this->holdersOf(self::POLICE, 20));
    }

    public function testEveryHolderAPartLinksStays(): void
    {
        $this->compoundParts->addEntry(5, [self::POLICE], [self::POLICE]);
        $this->compoundParts->addEntry(10, [self::POLICE], [self::POLICE]);
        $this->addPoliceOperation(20);

        self::assertSame(
            [5 => 1.0, 10 => 0.5],
            $this->compoundParts->holdersLinkedBeyondASharedCompound([5 => 1.0, 10 => 0.5], self::POLICE, 20),
        );
    }

    public function testAPartLinksTheBareWord(): void
    {
        $this->addPoliceOperation(10);
        $this->compoundParts->addEntry(20, [self::POLICE], [self::POLICE]);

        self::assertSame([10 => 1.0], $this->holdersOf(self::POLICE, 20));
    }

    public function testAPartLinksTwoDifferentCompounds(): void
    {
        $this->addPoliceOperation(10);
        $this->compoundParts->addEntry(
            20,
            [self::FIRE_OPERATION, self::FIRE_BRIGADE, self::OPERATION],
            [self::FIRE_OPERATION],
        );

        self::assertSame([10 => 1.0], $this->holdersOf(self::OPERATION, 20));
    }

    public function testAPartLinksNothingWhenOneEntryHoldsItOnlyThroughASharedCompound(): void
    {
        $this->compoundParts->addEntry(
            10,
            [self::POLICE_OPERATION, self::FIRE_OPERATION, self::POLICE, self::OPERATION, self::FIRE_BRIGADE],
            [self::POLICE_OPERATION, self::FIRE_OPERATION],
        );
        $this->addPoliceOperation(20);

        self::assertSame([], $this->holdersOf(self::OPERATION, 20));
    }

    public function testAnEntryHoldsOnlyTheStemsNoneOfItsWordsHoldsAsParts(): void
    {
        $this->compoundParts->addEntry(
            10,
            [self::POLICE_OPERATION, self::POLICE, self::OPERATION],
            [self::POLICE_OPERATION, self::POLICE],
        );

        self::assertTrue($this->compoundParts->isOnlyAPartIn(10, self::OPERATION));
        self::assertFalse($this->compoundParts->isOnlyAPartIn(10, self::POLICE));
        self::assertFalse($this->compoundParts->isOnlyAPartIn(10, self::POLICE_OPERATION));
    }

    public function testAnEntryAddedBeforeItsWordWasFoundACompoundHoldsItOnceTold(): void
    {
        $this->compoundParts = new CompoundParts();
        $policeOperationAndPolice = [self::POLICE_OPERATION, self::POLICE];
        $this->compoundParts->addEntry(10, $policeOperationAndPolice, $policeOperationAndPolice);
        $this->compoundParts->addCompound(self::POLICE_OPERATION, [self::POLICE, self::OPERATION]);
        $this->compoundParts->holdCompoundIn(self::POLICE_OPERATION, [10]);
        $this->addPoliceOperation(20);

        self::assertSame([], $this->holdersOf(self::POLICE, 20));
    }

    public function testAStemIsACompoundOnceAWordGivesItParts(): void
    {
        $this->compoundParts->addCompound(self::STATION, []);

        self::assertTrue($this->compoundParts->isCompound(self::POLICE_OPERATION));
        self::assertFalse($this->compoundParts->isCompound(self::STATION));
    }

    private function addPoliceOperation(int $entryId): void
    {
        $this->compoundParts->addEntry(
            $entryId,
            [self::POLICE_OPERATION, self::POLICE, self::OPERATION],
            [self::POLICE_OPERATION],
        );
    }

    /** @return array<int, float> */
    private function holdersOf(int $stemId, int $entryId): array
    {
        return $this->compoundParts->holdersLinkedBeyondASharedCompound([10 => 1.0], $stemId, $entryId);
    }
}
