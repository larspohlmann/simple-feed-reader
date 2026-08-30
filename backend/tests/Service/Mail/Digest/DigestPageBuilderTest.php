<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\DigestPageBuilder;
use PHPUnit\Framework\TestCase;

final class DigestPageBuilderTest extends TestCase
{
    private function entry(string $title): DigestEntry
    {
        return new DigestEntry($title, 'Feed', '', 'https://example.com/e', null, null, null);
    }

    private function group(string $term, int $count, int $totalCount): DigestGroup
    {
        $entries = array_map(fn (int $i): DigestEntry => $this->entry("{$term} {$i}"), range(1, $count));

        return new DigestGroup($term, $totalCount, $entries, $totalCount > $count, "https://example.com/?q={$term}");
    }

    public function testCapsTotalCardsAndMarksOverflowGroupsHeadingOnly(): void
    {
        $model = new DigestModel(
            [$this->group('a', 10, 10), $this->group('b', 10, 10), $this->group('c', 10, 40)],
            60,
        );

        $page = (new DigestPageBuilder())->build($model, 30);

        self::assertCount(10, $page->groups[0]->cards);
        self::assertSame(0, $page->groups[0]->remaining);
        self::assertCount(10, $page->groups[1]->cards);
        self::assertCount(10, $page->groups[2]->cards, 'The third group fills the budget to exactly 30.');
        self::assertSame(30, $page->groups[2]->remaining, 'Its 40 matches, minus the 10 shown.');
        self::assertSame(60, $page->totalCount);
    }

    public function testGroupsPastTheBudgetGetNoCards(): void
    {
        $model = new DigestModel(
            [$this->group('a', 30, 30), $this->group('b', 5, 5)],
            35,
        );

        $page = (new DigestPageBuilder())->build($model, 30);

        self::assertCount(30, $page->groups[0]->cards);
        self::assertCount(0, $page->groups[1]->cards);
        self::assertSame(5, $page->groups[1]->remaining);
    }
}
