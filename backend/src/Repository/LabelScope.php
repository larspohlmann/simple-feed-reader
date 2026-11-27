<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\EntryView;
use App\Exception\ValidationException;

final readonly class LabelScope
{
    private const string UNLABELED = 'unlabeled';

    private function __construct(public ?int $labelId)
    {
    }

    public static function forLabel(int $labelId): self
    {
        return new self($labelId);
    }

    public static function unlabeled(): self
    {
        return new self(null);
    }

    /**
     * @throws ValidationException for a label outside favorites and kept, or a value neither an id nor "unlabeled"
     */
    public static function fromRequestValue(?string $label, EntryView $view): ?self
    {
        if ($label === null) {
            return null;
        }
        if ($view !== EntryView::Favorites && $view !== EntryView::Kept) {
            throw new ValidationException(['label' => ['A label needs view=favorites or view=kept.']]);
        }
        if ($label === self::UNLABELED) {
            return self::unlabeled();
        }
        $labelId = filter_var($label, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($labelId === false) {
            throw new ValidationException(['label' => ['A label is an id or "unlabeled".']]);
        }

        return self::forLabel($labelId);
    }
}
