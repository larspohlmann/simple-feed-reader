<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostCountsSource;

use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Social\Exception\PostNotPubliclyCountableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\Support\MastodonStatusAddress;
use App\Service\Social\Social\Support\PostCounts;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Asks the status's own instance, anonymously: the counts every reader of the post sees there. */
#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonOriginCountsSource implements PostCountsSourceInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
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
