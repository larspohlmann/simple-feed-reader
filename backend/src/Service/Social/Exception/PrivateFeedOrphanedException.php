<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class PrivateFeedOrphanedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This private feed has lost its social account.');
    }
}
