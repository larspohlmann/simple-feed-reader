<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

/**
 * The URL named a provider this deployment does not offer: a typo, a probe, or one without credentials. All three
 * must look alike, or the difference would reveal which providers this deployment holds keys for.
 */
final class UnknownProviderException extends OAuthException
{
}
