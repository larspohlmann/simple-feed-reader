<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;

final class CommentsFailures
{
    private const int RETRY_AFTER_UNNAMED_WAIT = 60;

    private function __construct()
    {
    }

    public static function throttled(SocialNetworkThrottledException $exception): CommentsThrottledException
    {
        return new CommentsThrottledException(
            $exception->getMessage(),
            $exception->retryAfterSeconds ?? self::RETRY_AFTER_UNNAMED_WAIT,
            $exception,
        );
    }

    public static function unavailable(\Throwable $exception): CommentsUnavailableException
    {
        return new CommentsUnavailableException($exception->getMessage(), previous: $exception);
    }
}
