<?php

declare(strict_types=1);

namespace App\Service\Version\Exception;

/**
 * A version.json exists but cannot be trusted. Distinct from its absence, which
 * is the normal state of a local checkout and reports a development build.
 */
final class MalformedVersionFileException extends \RuntimeException
{
}
