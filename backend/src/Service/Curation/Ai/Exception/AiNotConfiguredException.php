<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/** The account has no provider row: nothing to list, choose from, or forget. */
final class AiNotConfiguredException extends \RuntimeException
{
}
