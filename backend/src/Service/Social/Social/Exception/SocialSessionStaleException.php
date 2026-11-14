<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

/** The network refused a session as expired or invalid: a fresh session may still be accepted. */
final class SocialSessionStaleException extends \RuntimeException
{
}
