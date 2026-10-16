<?php

declare(strict_types=1);

namespace App\Service\Social\PostCountsSource;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\PostNotPubliclyCountableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Support\MastodonStatusAddress;
use App\Service\Social\Support\PostCounts;

/** Asks the status's own instance, anonymously: the counts every reader of the post sees there. */
final readonly class MastodonOriginCountsSource implements PostCountsSourceInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function countsOf(string $address): SocialCountsModel
    {
        $statusUrl = MastodonStatusAddress::ofOrNull($address)?->apiUrl()
            ?? throw new PostNotPubliclyCountableException('The address names no status on a Mastodon instance.');
        $answer = MastodonApiClient::acceptedPublicStatus(
            $this->mastodon->answer(ApiRequestModel::get($statusUrl)),
            static fn (string $message): PostNotPubliclyCountableException
                => new PostNotPubliclyCountableException($message),
        );
        $status = JsonNodeModel::of($answer->json());
        if (!\in_array($address, [$status->string('uri'), $status->string('url')], true)) {
            throw new PostNotPubliclyCountableException('The instance answered with another status.');
        }

        return PostCounts::ofMastodonStatus($status)
            ?? throw new SocialNetworkUnreachableException('The instance answered a status without its counts.');
    }
}
