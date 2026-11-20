<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\EntryStemIdsModel;
use PHPUnit\Framework\TestCase;

final class EntryStemIdsModelTest extends TestCase
{
    public function testTheBodyKeepsOnlyTheStemsTheHeadlineLacks(): void
    {
        $stems = EntryStemIdsModel::fromHeadlinesAndBodies(
            [[3 => true, 1 => true]],
            [[1 => true, 7 => true, 5 => true]],
        );

        self::assertSame([3, 1], $stems->headlineIds);
        self::assertSame([7, 5], $stems->bodyIds);
    }

    public function testAStemIsAPartOnlyWhereNoWordOfEitherTextHoldsIt(): void
    {
        $headline = [1 => true, 2 => false, 3 => false];
        $body = [1 => false, 2 => true, 4 => false, 3 => false];

        $stems = EntryStemIdsModel::fromHeadlinesAndBodies([$headline], [$body]);

        self::assertSame([3, 4], $stems->partOnlyIds);
    }

    public function testEachPlaceHoldsTheStemsOfAllItsTexts(): void
    {
        $postTitle = [1 => true, 2 => false];
        $cardTitle = [2 => true, 3 => true];

        $stems = EntryStemIdsModel::fromHeadlinesAndBodies([$postTitle, $cardTitle], [[4 => false], [3 => true]]);

        self::assertSame([1, 2, 3], $stems->headlineIds);
        self::assertSame([4], $stems->bodyIds);
        self::assertSame([4], $stems->partOnlyIds);
    }
}
