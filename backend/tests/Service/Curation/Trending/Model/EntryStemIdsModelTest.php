<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\EntryStemIdsModel;
use PHPUnit\Framework\TestCase;

final class EntryStemIdsModelTest extends TestCase
{
    public function testTheBodyKeepsOnlyTheStemsTheHeadlineLacks(): void
    {
        $stems = EntryStemIdsModel::of([3 => true, 1 => true], [1 => true, 7 => true, 5 => true]);

        self::assertSame([3, 1], $stems->headlineIds);
        self::assertSame([7, 5], $stems->bodyIds);
    }

    public function testAStemIsAPartOnlyWhereNoWordOfEitherTextHoldsIt(): void
    {
        $stems = EntryStemIdsModel::of([1 => true, 2 => false, 3 => false], [2 => true, 4 => false, 3 => false]);

        self::assertSame([3, 4], $stems->partOnlyIds);
    }
}
