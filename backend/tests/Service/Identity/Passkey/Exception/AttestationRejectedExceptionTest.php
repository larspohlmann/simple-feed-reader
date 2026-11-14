<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Exception;

use App\Service\Passkey\Exception\AttestationRejectedException;
use PHPUnit\Framework\TestCase;

final class AttestationRejectedExceptionTest extends TestCase
{
    public function testTheCauseIsChainedForTheLog(): void
    {
        $cause = new \LengthException('Credential id is too long to store.');

        self::assertSame($cause, (new AttestationRejectedException($cause))->getPrevious());
    }
}
