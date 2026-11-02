<?php

declare(strict_types=1);

namespace App\Service\Social\HomeServerCheck;

use App\Enum\SocialNetwork;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\MastodonStatusModel;
use App\Service\Social\Support\MastodonStatusAddress;
use Symfony\Component\HttpFoundation\Response;

/** Asks the status's own instance, anonymously: only its 404 proves the status deleted. */
final readonly class MastodonHomeServerCheck implements HomeServerCheckInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function confirmsDeleted(string $address): bool
    {
        $status = MastodonStatusAddress::ofOrNull($address);
        if (null === $status || !self::isOnItsOwnServer($status)) {
            return false;
        }

        return Response::HTTP_NOT_FOUND === $this->mastodon->answer(ApiRequestModel::get($status->apiUrl()))->status;
    }

    private static function isOnItsOwnServer(MastodonStatusModel $status): bool
    {
        $account = substr($status->authorPrefix, \strlen('https://' . $status->host . '/'));

        return !str_contains(ltrim($account, '@'), '@');
    }
}
