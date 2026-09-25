<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/**
 * The attested credential id already exists; the unique constraint is global. Reporting it is an accepted, narrow
 * existence oracle: ~32 bytes of authenticator entropy mean it only confirms an id the caller already holds.
 */
final class DuplicatePasskeyException extends \RuntimeException
{
}
