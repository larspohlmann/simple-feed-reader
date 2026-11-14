<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

final class NetworkWithoutFeedsException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('Lemmy carries no feeds.');
    }
}
