<?php

declare(strict_types=1);

namespace App\Exception;

/** The ids a request selected are not all the caller's own, or contradict each other; the message says which. */
final class InvalidSelectionException extends \RuntimeException
{
}
