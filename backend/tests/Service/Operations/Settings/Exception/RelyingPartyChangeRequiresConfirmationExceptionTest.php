<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings\Exception;

use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use PHPUnit\Framework\TestCase;

final class RelyingPartyChangeRequiresConfirmationExceptionTest extends TestCase
{
    /** Pins both sentences and their order: the admin acts on this text to know what to resend. */
    public function testTheMessageNamesTheCountAndTheConfirmationField(): void
    {
        $exception = new RelyingPartyChangeRequiresConfirmationException(3);

        self::assertSame(3, $exception->invalidatedPasskeyCount);
        self::assertSame(
            'Changing the passkey relying party id invalidates 3 enrolled passkey(s). '
            . 'Resend the request with invalidateExistingPasskeys set to confirm.',
            $exception->getMessage(),
        );
    }
}
