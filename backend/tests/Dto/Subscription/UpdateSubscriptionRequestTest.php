<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\UpdateSubscriptionRequest;
use PHPUnit\Framework\TestCase;

final class UpdateSubscriptionRequestTest extends TestCase
{
    public function testToChangeCarriesTheTitleTheTagsAndBothFlags(): void
    {
        $change = (new UpdateSubscriptionRequest('Mine', [7, 8], false, true))->toChange();

        self::assertSame(
            ['customTitle' => 'Mine', 'tagIds' => [7, 8], 'includeInAllItems' => false, 'includeInForYou' => true],
            get_object_vars($change),
        );
    }
}
