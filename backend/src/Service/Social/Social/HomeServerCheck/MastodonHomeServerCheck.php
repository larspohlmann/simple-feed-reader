<?php

declare(strict_types=1);

namespace App\Service\Social\Social\HomeServerCheck;

use App\Enum\SocialNetwork;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Support\MastodonStatusAddress;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Response;

/** Asks the status's own instance, anonymously: only its 404 proves the status deleted. */
#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonHomeServerCheck implements HomeServerCheckInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public function confirmsDeleted(string $address): bool
    {
        $status = MastodonStatusAddress::ofOrNull($address);
        if (null === $status || !$status->isOnItsOwnServer()) {
            return false;
        }

        return Response::HTTP_NOT_FOUND === $this->mastodon->answer(ApiRequestModel::get($status->apiUrl()))->status;
    }
}
