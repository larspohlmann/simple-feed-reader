<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai\Exception;

/** No configuration with that id belongs to this account. */
final class ConfigurationNotFoundException extends \RuntimeException
{
}
