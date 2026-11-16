<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Exception;

class SocialNetworkUnreachableException extends \RuntimeException
{
    public static function from(\Throwable $previous): self
    {
        return new self($previous->getMessage(), 0, $previous);
    }

    /**
     * @template Result
     *
     * @param \Closure(): Result $call
     *
     * @return Result
     */
    public static function around(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (SocialPostNotFoundException | SocialPostRefusedException $exception) {
            throw self::from($exception);
        }
    }
}
