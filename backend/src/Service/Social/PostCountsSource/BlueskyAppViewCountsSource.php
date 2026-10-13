<?php

declare(strict_types=1);

namespace App\Service\Social\PostCountsSource;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Support\PostCounts;

final readonly class BlueskyAppViewCountsSource implements PostCountsSourceInterface
{
    private const string THROTTLED = "Bluesky's AppView asked for fewer requests.";

    public function __construct(private AppViewClient $appView)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function countsOf(string $address): SocialCountsModel
    {
        $throttledForSeconds = $this->appView->throttledForSeconds();
        if ($throttledForSeconds > 0) {
            throw new SocialNetworkThrottledException(self::THROTTLED, $throttledForSeconds);
        }

        return PostCounts::ofBlueskyPost($this->postAt($address))
            ?? throw new SocialNetworkUnreachableException("Bluesky's AppView answered a post without its counts.");
    }

    /** The one view asked for, whether the address names its author by DID or by handle. */
    private function postAt(string $address): JsonNodeModel
    {
        try {
            return array_values($this->appView->posts([$address]))[0]
                ?? throw new SocialPostNotFoundException('The post is no longer on Bluesky.');
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
