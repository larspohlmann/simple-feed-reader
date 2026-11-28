<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\SavedListExport\Model;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Repository\LabelScope;
use App\Service\Library\SavedListExport\Model\SavedListFilenameModel;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;
use PHPUnit\Framework\TestCase;

final class SavedListFilenameModelTest extends TestCase
{
    private const string NOW = '2026-11-28T10:00:00Z';

    public function testALabelNameIsSanitisedLikeABackupFilename(): void
    {
        $scope = $this->labelScope(EntryView::Kept, 3, 'Lesen & Später');

        self::assertSame(
            'simplefeedreader-kept-lesen-sp-ter-20261128.html',
            (new SavedListFilenameModel($scope, new \DateTimeImmutable(self::NOW)))->withExtension('.html'),
        );
    }

    public function testALabelThatSanitisesToNothingFallsBackToItsId(): void
    {
        $scope = $this->labelScope(EntryView::Kept, 7, '日本');

        self::assertSame(
            'simplefeedreader-kept-label-7-20261128.html',
            (new SavedListFilenameModel($scope, new \DateTimeImmutable(self::NOW)))->withExtension('.html'),
        );
    }

    public function testUnlabeledFavoritesCarryTheExtension(): void
    {
        $scope = new SavedListScopeModel(
            EntryView::Favorites,
            LabelScope::unlabeled(),
            null,
            null,
            ListOrder::NewestFirst,
        );

        self::assertSame(
            'simplefeedreader-favorites-unlabeled-20261128-starred.json',
            (new SavedListFilenameModel($scope, new \DateTimeImmutable(self::NOW)))->withExtension('-starred.json'),
        );
    }

    public function testAWholeListHasNoLabelPart(): void
    {
        $scope = new SavedListScopeModel(EntryView::Kept, null, null, null, ListOrder::NewestFirst);

        self::assertSame(
            'simplefeedreader-kept-20261128.html',
            (new SavedListFilenameModel($scope, new \DateTimeImmutable(self::NOW)))->withExtension('.html'),
        );
    }

    private function labelScope(EntryView $view, int $labelId, string $labelName): SavedListScopeModel
    {
        return new SavedListScopeModel(
            $view,
            LabelScope::forLabel($labelId),
            $labelId,
            $labelName,
            ListOrder::NewestFirst,
        );
    }
}
