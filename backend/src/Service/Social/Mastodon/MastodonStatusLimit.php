<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;

final readonly class MastodonStatusLimit
{
    private const string INSTANCE = '/api/v2/instance';
    private const int DEFAULT_MAX_CHARACTERS = 500;

    public function __construct(
        private MastodonAccountApi $accountApi,
        private MastodonApiClient $mastodon,
    ) {
    }

    public function resolveMaxCharacters(SocialAccount $account): int
    {
        return $this->findMaxCharacters($account) ?? self::DEFAULT_MAX_CHARACTERS;
    }

    public function findMaxCharacters(SocialAccount $account): ?int
    {
        return $this->fetchInstance($account)->node('configuration')->node('statuses')->int('max_characters');
    }

    private function fetchInstance(SocialAccount $account): JsonNodeModel
    {
        try {
            $answer = $this->mastodon->answer($this->accountApi->authorizedGet($account, self::INSTANCE));
        } catch (SocialNetworkUnreachableException | SocialCredentialsRejectedException) {
            return JsonNodeModel::fromValue([]);
        }

        return JsonNodeModel::fromValue($answer->isSuccessful() ? $answer->json() : []);
    }
}
