<?php

declare(strict_types=1);

namespace App\Repository\Exception;

/** No row with the requested id. Not Doctrine's EntityNotFoundException, which is a proxy miss and a bug. */
final class RecordNotFoundException extends \RuntimeException
{
}
