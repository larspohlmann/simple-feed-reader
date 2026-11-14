<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;

final class NetworkAnswer
{
    private function __construct()
    {
    }

    /**
     * @param \Closure(): ApiResponseModel $answer
     * @param string                       $network as the message names it: "Bluesky", "The Mastodon instance"
     *
     * @throws SocialNetworkUnreachableException only when no answer arrived; any status is returned
     */
    public static function orUnreachable(\Closure $answer, string $network): ApiResponseModel
    {
        try {
            return $answer();
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                sprintf('%s could not be reached.', $network),
                previous: $exception,
            );
        }
    }
}
