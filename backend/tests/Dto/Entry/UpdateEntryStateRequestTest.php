<?php

declare(strict_types=1);

namespace App\Tests\Dto\Entry;

use App\Dto\Entry\UpdateEntryStateRequest;
use PHPUnit\Framework\TestCase;

final class UpdateEntryStateRequestTest extends TestCase
{
    public function testToChangeCarriesEachFlagInItsOwnField(): void
    {
        $change = (new UpdateEntryStateRequest(isHidden: true, isFavorite: false, isKept: null, isViewed: false))
            ->toChange();

        self::assertSame(
            ['isHidden' => true, 'isFavorite' => false, 'isKept' => null, 'isViewed' => false],
            get_object_vars($change),
        );
    }

    public function testToChangeKeepsTheOtherFlagsApartToo(): void
    {
        $change = (new UpdateEntryStateRequest(isHidden: null, isFavorite: true, isKept: false, isViewed: null))
            ->toChange();

        self::assertSame(
            ['isHidden' => null, 'isFavorite' => true, 'isKept' => false, 'isViewed' => null],
            get_object_vars($change),
        );
    }
}
