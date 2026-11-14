<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai\Exception;

/** The account already holds AiProviderConfigurator::MAX_CONFIGURATIONS rows. */
final class TooManyConfigurationsException extends \RuntimeException
{
}
