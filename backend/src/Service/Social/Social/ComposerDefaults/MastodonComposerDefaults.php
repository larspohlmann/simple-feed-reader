<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ComposerDefaults;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\MastodonVisibility;
use App\Service\Social\Social\Support\SocialIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Mastodon's composer names the author and the thread's starter, never less private than the post it answers. */
#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonComposerDefaults implements ComposerDefaultsInterface
{
    private const string INSTANCE = '/api/v2/instance';
    private const int DEFAULT_MAX_CHARACTERS = 500;
    private const string DEFAULT_VISIBILITY = 'public';

    public function __construct(
        private MastodonAccountApi $accountApi,
        private MastodonApiClient $mastodon,
    ) {
    }

    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        $account = $target->account;

        return new ComposerDefaultsModel(
            $this->maxCharactersOf($account),
            MastodonVisibility::stricterOf(
                $target->responseContext->visibility ?? self::DEFAULT_VISIBILITY,
                $this->privacyOf($account),
            ),
            self::mentionsOf($target, $threadStarter),
        );
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel($this->maxCharactersOf($account), $this->privacyOf($account));
    }

    private function maxCharactersOf(SocialAccount $account): int
    {
        return $this->answerOf($account, self::INSTANCE)->node('configuration')->node('statuses')->int('max_characters')
            ?? self::DEFAULT_MAX_CHARACTERS;
    }

    private function privacyOf(SocialAccount $account): string
    {
        return $this->answerOf($account, MastodonApiClient::VERIFY_CREDENTIALS)->node('source')->string('privacy')
            ?? self::DEFAULT_VISIBILITY;
    }

    /**
     * @param \Closure(): ?SocialPostModel $threadStarter
     *
     * @return list<string>
     */
    private static function mentionsOf(SocialPostModel $target, \Closure $threadStarter): array
    {
        $account = $target->account;
        $authors = [$target->responseContext->authorIdentity, $threadStarter()?->responseContext->authorIdentity];
        $others = array_filter(
            array_map(static fn (?string $author): string => mb_strtolower($author ?? ''), $authors),
            static fn (string $author): bool => '' !== $author && !SocialIdentity::isAccount($author, $account),
        );

        return array_values(array_map(static fn (string $author): string => '@' . $author, array_unique($others)));
    }

    private function answerOf(SocialAccount $account, string $path): JsonNodeModel
    {
        try {
            $answer = $this->mastodon->answer($this->accountApi->authorizedGet($account, $path));
        } catch (SocialNetworkUnreachableException | SocialCredentialsRejectedException) {
            return JsonNodeModel::of([]);
        }

        return JsonNodeModel::of($answer->isSuccessful() ? $answer->json() : []);
    }
}
