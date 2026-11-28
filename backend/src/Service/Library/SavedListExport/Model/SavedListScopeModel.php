<?php

declare(strict_types=1);

namespace App\Service\Library\SavedListExport\Model;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Repository\LabelScope;

final readonly class SavedListScopeModel
{
    public function __construct(
        public EntryView $view,
        public ?LabelScope $labelScope,
        public ?int $labelId,
        public ?string $labelName,
        public ListOrder $order,
    ) {
    }

    public function exportName(): string
    {
        $listName = $this->listName();
        if ($this->isUnlabeled()) {
            return $listName . ' – Unlabeled';
        }

        return $this->labelName === null ? $listName : $listName . ' – ' . $this->labelName;
    }

    public function isUnlabeled(): bool
    {
        return $this->labelScope !== null && $this->labelScope->labelId === null;
    }

    public function listName(): string
    {
        return $this->view === EntryView::Favorites ? 'Favorites' : 'Kept';
    }
}
