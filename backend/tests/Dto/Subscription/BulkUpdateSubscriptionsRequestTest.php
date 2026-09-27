<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use PHPUnit\Framework\TestCase;

final class BulkUpdateSubscriptionsRequestTest extends TestCase
{
    public function testToChangeCarriesTheSelectionTheTagChangesAndTheFlags(): void
    {
        $change = (new BulkUpdateSubscriptionsRequest([1, 2], [3], [4], true, false))->toChange();

        self::assertSame(
            [
                'subscriptionIds' => [1, 2],
                'addTagIds' => [3],
                'removeTagIds' => [4],
                'includeInAllItems' => true,
                'includeInForYou' => false,
            ],
            get_object_vars($change),
        );
    }
}
