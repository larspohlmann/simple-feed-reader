<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup\Dto;

use App\Entity\InclusionChange;
use App\Entity\Tag;
use App\Enum\InclusionSurface;
use App\Service\Operations\Backup\Dto\TagLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class TagLineTest extends TestCase
{
    public function testALineWithoutAParentKeyReadsAsARoot(): void
    {
        $line = TagLine::fromLine(
            ['kind' => 'tag', 'name' => 'Reading', 'color' => null, 'icon' => null, 'position' => 0],
        );

        self::assertNull($line->parent);
    }

    public function testALineNamingAParentCarriesIt(): void
    {
        $line = TagLine::fromLine(
            [
                'kind' => 'tag', 'name' => 'Long reads', 'color' => null, 'icon' => null, 'position' => 1,
                'parent' => 'Reading',
            ],
        );

        self::assertSame('Reading', $line->parent);
    }

    public function testALineWithoutSwitchesReadsAsIncludedEverywhere(): void
    {
        $line = TagLine::fromLine(
            ['kind' => 'tag', 'name' => 'Reading', 'color' => null, 'icon' => null, 'position' => 0],
        );

        foreach (InclusionSurface::cases() as $surface) {
            self::assertTrue($line->inclusions->isIncludedIn($surface), $surface->name);
        }
    }

    public function testALineBuiltWithoutSwitchesIsIncludedEverywhere(): void
    {
        $line = new TagLine('Reading', null, null, 0);

        foreach (InclusionSurface::cases() as $surface) {
            self::assertTrue($line->inclusions->isIncludedIn($surface), $surface->name);
        }
    }

    public function testItReadsTheSwitchesTheLineStates(): void
    {
        $line = TagLine::fromLine([
            'kind' => 'tag', 'name' => 'Noise', 'color' => null, 'icon' => null, 'position' => 0,
            'includeInAllItems' => false, 'includeInForYou' => true, 'includeInSavedSearches' => false,
            'includeInTrending' => false, 'includeInYourStream' => true,
        ]);

        self::assertFalse($line->inclusions->isIncludedIn(InclusionSurface::AllItems));
        self::assertTrue($line->inclusions->isIncludedIn(InclusionSurface::ForYou));
        self::assertFalse($line->inclusions->isIncludedIn(InclusionSurface::SavedSearches));
        self::assertFalse($line->inclusions->isIncludedIn(InclusionSurface::Trending));
        self::assertTrue($line->inclusions->isIncludedIn(InclusionSurface::YourStream));
    }

    public function testItWritesTheTagsSwitches(): void
    {
        $tag = new Tag(BackupLineRecords::user(), 'Noise');
        $tag->changeInclusions(new InclusionChange(allItems: false));
        $tag->changeInclusions(new InclusionChange(savedSearches: false));
        $tag->changeInclusions(new InclusionChange(trending: false));

        self::assertSame(
            '{"kind":"tag","name":"Noise","color":null,"icon":null,"position":0,"parent":null,'
            . '"includeInAllItems":false,"includeInForYou":true,"includeInSavedSearches":false,'
            . '"includeInTrending":false,"includeInYourStream":true}',
            TagLine::fromTag($tag)->toBackupLine(),
        );
    }

    public function testItWritesAChildTagNamingItsParent(): void
    {
        $user = BackupLineRecords::user();
        $tag = new Tag($user, 'Long reads');
        $tag->setColor('#123456');
        $tag->setIcon('flask');
        $tag->setPosition(2);
        $tag->setParent(new Tag($user, 'Reading'));

        self::assertSame(
            '{"kind":"tag","name":"Long reads","color":"#123456","icon":"flask","position":2,"parent":"Reading",'
            . '"includeInAllItems":true,"includeInForYou":true,"includeInSavedSearches":true,"includeInTrending":true,'
            . '"includeInYourStream":true}',
            TagLine::fromTag($tag)->toBackupLine(),
        );
    }

    public function testItWritesARootTagWithANullParent(): void
    {
        self::assertSame(
            '{"kind":"tag","name":"Reading","color":null,"icon":null,"position":0,"parent":null,'
            . '"includeInAllItems":true,"includeInForYou":true,"includeInSavedSearches":true,"includeInTrending":true,'
            . '"includeInYourStream":true}',
            TagLine::fromTag(new Tag(BackupLineRecords::user(), 'Reading'))->toBackupLine(),
        );
    }

    public function testAnOlderLineTakesYourStreamFromForYou(): void
    {
        $line = TagLine::fromLine([
            'kind' => 'tag', 'name' => 'Noise', 'color' => null, 'icon' => null, 'position' => 0,
            'includeInForYou' => false,
        ]);

        self::assertFalse($line->inclusions->isIncludedIn(InclusionSurface::YourStream));
    }

    public function testALineRoundTripsYourStreamApartFromForYou(): void
    {
        $tag = new Tag(BackupLineRecords::user(), 'Noise');
        $tag->changeInclusions(new InclusionChange(forYou: true, yourStream: false));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(TagLine::fromTag($tag)->toBackupLine(), true, flags: \JSON_THROW_ON_ERROR);
        $line = TagLine::fromLine($decoded);

        self::assertTrue($line->inclusions->isIncludedIn(InclusionSurface::ForYou));
        self::assertFalse($line->inclusions->isIncludedIn(InclusionSurface::YourStream));
    }
}
