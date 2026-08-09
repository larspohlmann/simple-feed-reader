<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/** A configuration cannot become the active one before it has a chosen model. */
final class ModelRequiredForActivationException extends \RuntimeException
{
}
