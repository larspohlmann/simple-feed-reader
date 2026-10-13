<?php

declare(strict_types=1);

namespace App\Service\Social\PostCountsSource;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\PostNotPubliclyCountableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Support\MastodonStatusAddress;
use App\Service\Social\Support\PostCounts;
use Symfony\Component\HttpFoundation\Response;

/** Asks the status's own instance, anonymously: the counts every reader of the post sees there. */
final readonly class MastodonOriginCountsSource implements PostCountsSourceInterface
{
    private const array CLOSED_TO_THE_PUBLIC = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function countsOf(string $address): SocialCountsModel
    {
        $statusUrl = MastodonStatusAddress::statusApiUrlOrNull($address)
            ?? throw new PostNotPubliclyCountableException('The address names no status on a Mastodon instance.');
        $answer = self::accepted($this->mastodon->answer(ApiRequestModel::get($statusUrl)));
        $status = JsonNodeModel::of($answer->json());
        if (!\in_array($address, [$status->string('uri'), $status->string('url')], true)) {
            throw new PostNotPubliclyCountableException('The instance answered with another status.');
        }

        return PostCounts::ofMastodonStatus($status)
            ?? throw new SocialNetworkUnreachableException('The instance answered a status without its counts.');
    }

    private static function accepted(ApiResponseModel $answer): ApiResponseModel
    {
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on its Mastodon instance.');
        }
        if (\in_array($answer->status, self::CLOSED_TO_THE_PUBLIC, true)) {
            throw new PostNotPubliclyCountableException('The Mastodon instance shows its statuses to accounts only.');
        }

        return MastodonApiClient::accepted($answer);
    }
}
