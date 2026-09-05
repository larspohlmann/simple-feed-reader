<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/** No passkey with this id belongs to the caller — foreign ids included, see PasskeyRemoval. */
final class PasskeyNotFoundException extends ApiException
{
    public function __construct()
    {
        parent::__construct('passkey_not_found', 404, 'No such passkey');
    }
}
