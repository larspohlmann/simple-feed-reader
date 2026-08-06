<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The endpoint did not answer, or answered something that is not a model list.
 * Separate from CredentialsRejectedException because the two need different
 * advice: check the address, versus check the key.
 */
final class ProviderUnreachableException extends \RuntimeException
{
}
