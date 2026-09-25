<?php

declare(strict_types=1);

namespace App\Service\Account\Exception;

/**
 * The change would leave no Active administrator; a suspended admin cannot approve or reinstate anyone. It does
 * not guard hasAnyAdmin()'s first-run-setup invariant, which stays status-blind on purpose.
 */
final class LastAdminException extends \RuntimeException
{
}
