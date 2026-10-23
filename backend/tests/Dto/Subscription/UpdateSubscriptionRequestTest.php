<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\UpdateSubscriptionRequest;
use PHPUnit\Framework\TestCase;

final class UpdateSubscriptionRequestTest extends TestCase
{
    public function testToChangeCarriesTheTitleTheTagsAndEveryFlag(): void
    {
        $change = (new UpdateSubscriptionRequest('Mine', [7, 8], false, true, false))->toChange();

        self::assertSame('Mine', $change->customTitle);
        self::assertSame([7, 8], $change->tagIds);
        self::assertSame(
            ['includeInAllItems' => false, 'includeInForYou' => true, 'includeInSavedSearches' => false],
            get_object_vars($change->inclusions),
        );
    }
}
