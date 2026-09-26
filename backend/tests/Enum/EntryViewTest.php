<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\EntryView;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class EntryViewTest extends TestCase
{
    public function testAnAbsentViewIsAll(): void
    {
        self::assertSame(EntryView::All, EntryView::fromRequestValue(null));
    }

    public function testEachRequestValueNamesItsView(): void
    {
        self::assertSame(EntryView::All, EntryView::fromRequestValue('all'));
        self::assertSame(EntryView::Unread, EntryView::fromRequestValue('unread'));
        self::assertSame(EntryView::Favorites, EntryView::fromRequestValue('favorites'));
        self::assertSame(EntryView::Kept, EntryView::fromRequestValue('kept'));
        self::assertSame(EntryView::Viewed, EntryView::fromRequestValue('viewed'));
        self::assertSame(EntryView::ForYou, EntryView::fromRequestValue('for-you'));
    }

    public function testAnEmptyOrUnknownViewIsAValidationErrorOnTheViewField(): void
    {
        foreach (['', 'bogus', 'Unread'] as $value) {
            try {
                EntryView::fromRequestValue($value);
                self::fail("The view '{$value}' must be rejected.");
            } catch (ValidationException $exception) {
                self::assertSame(
                    ['view' => ['Unknown view. Use one of: all, unread, favorites, kept, viewed, for-you.']],
                    $exception->errors,
                );
            }
        }
    }

    public function testOnlyAllAndUnreadAreChronological(): void
    {
        $chronological = array_values(array_filter(
            EntryView::cases(),
            static fn (EntryView $view): bool => $view->isChronological(),
        ));

        self::assertSame([EntryView::All, EntryView::Unread], $chronological);
    }
}
