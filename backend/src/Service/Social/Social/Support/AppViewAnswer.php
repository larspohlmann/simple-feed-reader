<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;

final class AppViewAnswer
{
    private const string THROTTLED = "Bluesky's AppView asked for fewer requests.";

    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $ask
     *
     * @return T
     *
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public static function orSocialFailure(int $throttledForSeconds, \Closure $ask): mixed
    {
        if ($throttledForSeconds > 0) {
            throw new SocialNetworkThrottledException(self::THROTTLED, $throttledForSeconds);
        }

        try {
            return $ask();
        } catch (FeedThrottledException $exception) {
            throw new SocialNetworkThrottledException(self::THROTTLED, $exception->retryAfterSeconds, $exception);
        } catch (FetchException | AppViewAnswerException $exception) {
            throw new SocialNetworkUnreachableException(
                "Bluesky's AppView could not be reached.",
                previous: $exception,
            );
        }
    }
}
