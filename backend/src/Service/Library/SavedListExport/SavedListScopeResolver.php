<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\LabelRepository;
use App\Repository\LabelScope;
use App\Service\Library\SavedListExport\Model\SavedListScopeModel;

final readonly class SavedListScopeResolver
{
    public function __construct(private LabelRepository $labels)
    {
    }

    /**
     * @throws ValidationException when the view, the label or the order is unusable
     * @throws RecordNotFoundException when the label is unknown or another user's
     */
    public function requireScope(int $userId, ?string $view, ?string $label, ?string $order): SavedListScopeModel
    {
        $entryView = $this->requireExportableView($view);
        $labelScope = LabelScope::fromRequestValue($label, $entryView);
        $listOrder = ListOrder::fromRequestValue($order);
        $labelId = $labelScope?->labelId;

        return new SavedListScopeModel(
            $entryView,
            $labelScope,
            $labelId,
            $labelId === null ? null : $this->labels->requireOneForUser($userId, $labelId)->getName(),
            $listOrder,
        );
    }

    private function requireExportableView(?string $view): EntryView
    {
        $entryView = $view === null ? null : EntryView::fromRequestValue($view);
        if ($entryView !== EntryView::Favorites && $entryView !== EntryView::Kept) {
            throw new ValidationException(['view' => ['An export needs view=favorites or view=kept.']]);
        }

        return $entryView;
    }
}
