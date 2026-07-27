<?php

declare(strict_types=1);

namespace App\Service\Catalog\Exception;

/**
 * The catalog document is not usable. Thrown BEFORE anything is written, so a
 * bad import changes nothing at all.
 */
final class InvalidCatalogDocumentException extends \RuntimeException
{
}
