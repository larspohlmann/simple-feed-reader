<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity\Passkey\Exception;

use App\Service\Identity\Passkey\Exception\AssertionRejectedException;
use PHPUnit\Framework\TestCase;

final class AssertionRejectedExceptionTest extends TestCase
{
    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \RuntimeException('CBOR decode failed');

        self::assertSame($cause, (new AssertionRejectedException($cause))->getPrevious());
    }
}
