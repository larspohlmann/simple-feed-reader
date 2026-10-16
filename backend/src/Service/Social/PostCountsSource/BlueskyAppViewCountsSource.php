<?php

declare(strict_types=1);

namespace App\Service\Social\PostCountsSource;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Support\AppViewAnswer;
use App\Service\Social\Support\PostCounts;

final readonly class BlueskyAppViewCountsSource implements PostCountsSourceInterface
{
    public function __construct(private AppViewClient $appView)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function countsOf(string $address): SocialCountsModel
    {
        $post = AppViewAnswer::orSocialFailure(
            $this->appView->throttledForSeconds(),
            fn (): JsonNodeModel => $this->postAt($address),
        );

        return PostCounts::ofBlueskyPost($post)
            ?? throw new SocialNetworkUnreachableException("Bluesky's AppView answered a post without its counts.");
    }

    /** The one view asked for, whether the address names its author by DID or by handle. */
    private function postAt(string $address): JsonNodeModel
    {
        return array_values($this->appView->posts([$address]))[0]
            ?? throw new SocialPostNotFoundException('The post is no longer on Bluesky.');
    }
}
