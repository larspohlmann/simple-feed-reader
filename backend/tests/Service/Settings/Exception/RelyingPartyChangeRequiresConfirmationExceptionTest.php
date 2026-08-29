<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings\Exception;

use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use PHPUnit\Framework\TestCase;

final class RelyingPartyChangeRequiresConfirmationExceptionTest extends TestCase
{
    /**
     * Pins the exact wording and ORDER of the message's two sentences, not
     * merely that it mentions the count: an admin acts on this detail text
     * to know what to resend, so a sentence reordering that still merely
     * "contains a number" would be a silent regression.
     */
    public function testTheDetailNamesTheCountAndTheConfirmationField(): void
    {
        $exception = new RelyingPartyChangeRequiresConfirmationException(3);

        self::assertSame('relying_party_change_requires_confirmation', $exception->type);
        self::assertSame(409, $exception->status);
        self::assertSame(3, $exception->invalidatedPasskeyCount);
        self::assertSame(
            'Changing the passkey relying party id invalidates 3 enrolled passkey(s). '
            . 'Resend the request with invalidateExistingPasskeys set to confirm.',
            $exception->detail,
        );
    }
}
