<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Mastodon\MastodonPostWriter;
use App\Service\Social\Mastodon\MastodonStatusLimit;
use App\Service\Social\Mastodon\Support\MastodonVisibility;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\PostEmbedModelInterface;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\SocialIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Mastodon's composer names the author and the thread's starter, never less private than the post it answers. */
#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonComposerDefaults implements ComposerDefaultsInterface
{
    private const string DEFAULT_VISIBILITY = 'public';

    public function __construct(
        private MastodonAccountApi $accountApi,
        private MastodonApiClient $mastodon,
        private MastodonStatusLimit $statusLimit,
    ) {
    }

    public function forPublication(
        SocialAccount $account,
        PublicationModel $publication,
        \Closure $threadStarter,
    ): ComposerDefaultsModel {
        $defaults = new ComposerDefaultsModel(
            $this->statusLimit->resolveMaxCharacters($account),
            $this->resolveVisibility($account, $publication),
            self::collectMentions($account, $publication->inReplyTo, $threadStarter),
        );

        return $publication->embed instanceof LinkEmbedModel
            ? $defaults->withMaxCharacters($defaults->maxCharacters - MastodonPostWriter::LINK_ROOM)
            : $defaults;
    }

    private function resolveVisibility(SocialAccount $account, PublicationModel $publication): string
    {
        $answered = $publication->inReplyTo ?? self::readQuotedPost($publication->embed);
        if (null === $answered) {
            return $this->resolvePrivacy($account);
        }

        return MastodonVisibility::pickStricter(
            $answered->postContext->visibility ?? self::DEFAULT_VISIBILITY,
            $this->resolvePrivacy($account),
        );
    }

    private static function readQuotedPost(?PostEmbedModelInterface $embed): ?SocialPostModel
    {
        return $embed instanceof QuotedPostEmbedModel ? $embed->post : null;
    }

    private function resolvePrivacy(SocialAccount $account): string
    {
        return $this->fetchOwnAccount($account)->node('source')->string('privacy')
            ?? self::DEFAULT_VISIBILITY;
    }

    /**
     * @param \Closure(): ?SocialPostModel $threadStarter
     *
     * @return list<string>
     */
    private static function collectMentions(
        SocialAccount $account,
        ?SocialPostModel $inReplyTo,
        \Closure $threadStarter,
    ): array {
        if (null === $inReplyTo) {
            return [];
        }

        $authors = [$inReplyTo->postContext->authorIdentity, $threadStarter()?->postContext->authorIdentity];
        $others = array_filter(
            array_map(static fn (?string $author): string => mb_strtolower($author ?? ''), $authors),
            static fn (string $author): bool => '' !== $author && !SocialIdentity::isAccount($author, $account),
        );

        return array_values(array_map(static fn (string $author): string => '@' . $author, array_unique($others)));
    }

    private function fetchOwnAccount(SocialAccount $account): JsonNodeModel
    {
        try {
            $answer = $this->mastodon->answer(
                $this->accountApi->authorizedGet($account, MastodonApiClient::VERIFY_CREDENTIALS),
            );
        } catch (SocialNetworkUnreachableException | SocialCredentialsRejectedException) {
            return JsonNodeModel::fromValue([]);
        }

        return JsonNodeModel::fromValue($answer->isSuccessful() ? $answer->json() : []);
    }
}
