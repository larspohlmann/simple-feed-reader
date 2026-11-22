<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing;

use App\Entity\SocialAccount;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerContextModel;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\ComposerKind;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\ResponseTargetModel;
use App\Service\Social\Publishing\Model\SharedLinkModel;
use App\Service\Social\Publishing\Support\ComposerStartingText;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\SharedPageCards;
use App\Service\Social\Social\SocialPostAddresses;
use App\Service\Social\Social\SocialPostFinder;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class ComposerContexts
{
    /** @param ServiceLocator<ComposerDefaultsInterface> $defaults */
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialPostFinder $posts,
        private SharedPageCards $pageCards,
        #[AutowireLocator('app.social_composer_defaults')]
        private ServiceLocator $defaults,
        private SocialPostAddresses $postAddresses,
    ) {
    }

    public function buildReplyContext(SocialPostModel $post, ResponseTargetModel $target): ComposerContextModel
    {
        $defaults = $this->selectDefaults($post->account)->forPublication(
            $post->account,
            self::startPublication()->withReplyTo($post),
            $this->buildThreadStarterLookup($target, $post),
        );
        $text = ComposerStartingText::mentioning($defaults->mentions);

        return $this->buildPostContext($post, ComposerKind::Reply, $defaults, $text);
    }

    public function buildQuoteContext(SocialPostModel $post, string $quote): ComposerContextModel
    {
        $defaults = $this->selectDefaults($post->account)->forPublication(
            $post->account,
            self::startPublication()->withEmbed(new QuotedPostEmbedModel($post)),
            self::noThreadStarter(),
        );
        $text = ComposerStartingText::quoting($quote, $defaults->maxCharacters);

        return $this->buildPostContext($post, ComposerKind::Quote, $defaults, $text);
    }

    public function buildLinkContext(SocialAccount $account, SharedLinkModel $link): ComposerContextModel
    {
        $defaults = $this->selectDefaults($account)->forPublication(
            $account,
            self::startPublication()->withEmbed(new LinkEmbedModel($link->url, $link->title)),
            self::noThreadStarter(),
        );

        return new ComposerContextModel(
            $this->connectedBeside($account),
            $account,
            canSend: true,
            defaults: $defaults,
            subject: $this->pageCards->resolveCard($link->url),
            kind: ComposerKind::Link,
            text: self::buildLinkText($link, $defaults),
        );
    }

    private static function buildLinkText(SharedLinkModel $link, ComposerDefaultsModel $defaults): string
    {
        if (null !== $defaults->lemmyPost) {
            return ComposerStartingText::quoting($link->quote, $defaults->maxCharacters);
        }

        return ComposerStartingText::sharing($link, $defaults->maxCharacters);
    }

    private function buildPostContext(
        SocialPostModel $post,
        ComposerKind $kind,
        ComposerDefaultsModel $defaults,
        string $text,
    ): ComposerContextModel {
        return new ComposerContextModel(
            $this->connectedBeside($post->account),
            $post->account,
            $kind->isAllowedBy($post->postContext),
            $defaults,
            $post->postContext->preview,
            $kind,
            $text,
        );
    }

    private function selectDefaults(SocialAccount $account): ComposerDefaultsInterface
    {
        return $this->defaults->get($account->getNetwork()->value);
    }

    private static function startPublication(): PublicationModel
    {
        return PublicationModel::fromDraft(new PostDraftModel(''));
    }

    /** @return \Closure(): ?SocialPostModel */
    private static function noThreadStarter(): \Closure
    {
        return static fn (): ?SocialPostModel => null;
    }

    /** @return list<SocialAccount> */
    private function connectedBeside(SocialAccount $account): array
    {
        return $this->socialAccounts->findConnectedOnNetwork($account->getUser(), $account->getNetwork());
    }

    /** @return \Closure(): ?SocialPostModel */
    private function buildThreadStarterLookup(ResponseTargetModel $target, SocialPostModel $post): \Closure
    {
        if (null === $target->address) {
            return static fn (): SocialPostModel => $post;
        }

        return fn (): ?SocialPostModel => $this->tryFindThreadStarter($post, $target);
    }

    private function tryFindThreadStarter(SocialPostModel $post, ResponseTargetModel $target): ?SocialPostModel
    {
        try {
            return $this->posts->findAs($post->account, $this->postAddresses->requireRespondablePost($target->entry));
        } catch (
            SocialPostNotFoundException
            | SocialAccountNeedsReconnectException
            | SocialCredentialsRejectedException
            | SocialNetworkUnreachableException
        ) {
            return null;
        }
    }
}
