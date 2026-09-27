<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\MoveFeedToTagRequest;
use PHPUnit\Framework\TestCase;

final class MoveFeedToTagRequestTest extends TestCase
{
    public function testToMoveCarriesTheSourceTheTargetAndThePosition(): void
    {
        $move = (new MoveFeedToTagRequest(3, 5, 2))->toMove();

        self::assertSame(['fromTagId' => 3, 'toTagId' => 5, 'position' => 2], get_object_vars($move));
    }
}
