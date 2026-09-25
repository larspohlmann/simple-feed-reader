<?php

declare(strict_types=1);

namespace App\Service\Ai\Exception;

/**
 * The stored API key no longer opens (a rotated instance secret, an edited or a moved row). Its own type,
 * apart from the provider refusals: only re-entering the key helps, and the client tells them apart by type.
 */
final class AiKeyUnreadableException extends \RuntimeException
{
}
