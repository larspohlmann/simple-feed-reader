<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai\Exception;

/** The picked connection is neither the stored pick nor able to serve the feature. */
final class ConnectionPickRejectedException extends \RuntimeException
{
}
