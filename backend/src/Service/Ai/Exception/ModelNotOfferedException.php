<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The chosen model is not in the list the provider offers. Raised on the model
 * write, so `ready` can never claim a model the provider does not have.
 */
final class ModelNotOfferedException extends \RuntimeException
{
}
