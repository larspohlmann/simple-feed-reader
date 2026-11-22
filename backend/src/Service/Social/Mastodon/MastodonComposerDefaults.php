<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Mastodon\Support\MastodonVisibility;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\SharedLinkModel;
use App\Service\Social\Publishing\Support\ComposerStartingText;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\SocialPostModel;
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

    public function resolveDefaults(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        $account = $target->account;

        return new ComposerDefaultsModel(
            $this->resolveMaxCharacters($account),
            MastodonVisibility::pickStricter(
                $target->postContext->visibility ?? self::DEFAULT_VISIBILITY,
                $this->resolvePrivacy($account),
            ),
            self::collectMentions($target, $threadStarter),
        );
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel($this->resolveMaxCharacters($account), $this->resolvePrivacy($account));
    }

    public function forLinkFrom(SocialAccount $account, SharedLinkModel $link): ComposerDefaultsModel
    {
        $defaults = $this->forAccount($account);

        return $defaults->withMaxCharacters($defaults->maxCharacters - ComposerStartingText::LINK_ROOM);
    }

    private function resolveMaxCharacters(SocialAccount $account): int
    {
        return $this->fetchAnswerOrEmpty($account, self::INSTANCE)
            ->node('configuration')->node('statuses')->int('max_characters')
            ?? self::DEFAULT_MAX_CHARACTERS;
    }

    private function resolvePrivacy(SocialAccount $account): string
    {
        return $this->fetchAnswerOrEmpty($account, MastodonApiClient::VERIFY_CREDENTIALS)
            ->node('source')->string('privacy')
            ?? self::DEFAULT_VISIBILITY;
    }

    /**
     * @param \Closure(): ?SocialPostModel $threadStarter
     *
     * @return list<string>
     */
    private static function collectMentions(SocialPostModel $target, \Closure $threadStarter): array
    {
        $account = $target->account;
        $authors = [$target->postContext->authorIdentity, $threadStarter()?->postContext->authorIdentity];
        $others = array_filter(
            array_map(static fn (?string $author): string => mb_strtolower($author ?? ''), $authors),
            static fn (string $author): bool => '' !== $author && !SocialIdentity::isAccount($author, $account),
        );

        return array_values(array_map(static fn (string $author): string => '@' . $author, array_unique($others)));
    }

    private function fetchAnswerOrEmpty(SocialAccount $account, string $path): JsonNodeModel
    {
        try {
            $answer = $this->mastodon->answer($this->accountApi->authorizedGet($account, $path));
        } catch (SocialNetworkUnreachableException | SocialCredentialsRejectedException) {
            return JsonNodeModel::fromValue([]);
        }

        return JsonNodeModel::fromValue($answer->isSuccessful() ? $answer->json() : []);
    }
}
