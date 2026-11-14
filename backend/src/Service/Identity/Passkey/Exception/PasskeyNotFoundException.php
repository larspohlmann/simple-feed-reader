<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

/** No passkey with this id belongs to the caller — foreign ids included, see PasskeyRemoval. */
final class PasskeyNotFoundException extends \RuntimeException
{
}
