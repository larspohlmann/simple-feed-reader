<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\ComposerContextModel;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\ComposerKind;
use App\Service\Social\Social\Model\ResponseKind;
use App\Service\Social\Social\Model\ResponseTargetModel;
use App\Service\Social\Social\Model\SharedLinkModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\ComposerStartingText;
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
        $defaults = $this->defaults->get($post->account->getNetwork()->value)
            ->resolveDefaults($post, $this->buildThreadStarterLookup($target, $post));
        $text = ComposerStartingText::mentioning($defaults->mentions);

        return $this->buildContext($post, ResponseKind::Reply, $defaults, $text);
    }

    public function buildQuoteContext(SocialPostModel $post): ComposerContextModel
    {
        return $this->buildQuotingContext($post, '');
    }

    public function buildLinkContext(SocialAccount $account, SharedLinkModel $link): ComposerContextModel
    {
        $defaults = $this->defaults->get($account->getNetwork()->value)->forLinkFrom($account, $link);

        return new ComposerContextModel(
            $this->connectedBeside($account),
            $account,
            canSend: true,
            defaults: $defaults,
            target: $this->pageCards->resolveCard($link->url),
            kind: ComposerKind::Link,
            text: $this->buildStartingText($link, $defaults),
        );
    }

    private function buildStartingText(SharedLinkModel $link, ComposerDefaultsModel $defaults): string
    {
        if (null !== $defaults->linkPost) {
            return ComposerStartingText::quoting($link->quote, $defaults->maxCharacters);
        }

        return ComposerStartingText::sharing($link, $defaults->maxCharacters);
    }

    /** A shared link that names a quotable post quotes it, starting with the selection the reader shares. */
    public function buildQuotedLinkContext(SocialPostModel $post, SharedLinkModel $link): ComposerContextModel
    {
        return $this->buildQuotingContext($post, $link->quote);
    }

    /** A quote names nobody, so it never asks for the thread's starter. */
    private function buildQuotingContext(SocialPostModel $post, string $quote): ComposerContextModel
    {
        $defaults = $this->defaults->get($post->account->getNetwork()->value)
            ->resolveDefaults($post, static fn (): ?SocialPostModel => null)
            ->withoutMentions();
        $text = ComposerStartingText::quoting($quote, $defaults->maxCharacters);

        return $this->buildContext($post, ResponseKind::Quote, $defaults, $text);
    }

    private function buildContext(
        SocialPostModel $post,
        ResponseKind $kind,
        ComposerDefaultsModel $defaults,
        string $text,
    ): ComposerContextModel {
        return new ComposerContextModel(
            $this->connectedBeside($post->account),
            $post->account,
            $post->postContext->allows($kind),
            $defaults,
            $post->postContext->preview,
            ComposerKind::from($kind->value),
            $text,
        );
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
