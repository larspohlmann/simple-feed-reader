<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use PHPUnit\Framework\TestCase;

final class BulkUpdateSubscriptionsRequestTest extends TestCase
{
    public function testToChangeCarriesTheSelectionTheTagChangesAndTheFlags(): void
    {
        $change = (new BulkUpdateSubscriptionsRequest([1, 2], [3], [4], true, false, false))->toChange();

        self::assertSame([1, 2], $change->subscriptionIds);
        self::assertSame([3], $change->addTagIds);
        self::assertSame([4], $change->removeTagIds);
        self::assertSame(
            ['includeInAllItems' => true, 'includeInForYou' => false, 'includeInSavedSearches' => false],
            get_object_vars($change->inclusions),
        );
    }
}
