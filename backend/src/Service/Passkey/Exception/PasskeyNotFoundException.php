<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * No passkey with this id belongs to the caller. Also the answer for another
 * account's id: a 403 there would confirm the id exists (see PasskeyRemoval).
 */
final class PasskeyNotFoundException extends ApiException
{
    public function __construct()
    {
        parent::__construct('passkey_not_found', 404, 'No such passkey');
    }
}
