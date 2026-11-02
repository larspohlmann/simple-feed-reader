<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\ComposerKind;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\ComposerStartingText;
use App\Service\Social\Support\SocialPostAddress;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class ComposerContexts
{
    /** @param ServiceLocator<ComposerDefaultsInterface> $defaults */
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialPostFinder $posts,
        private SharedPageCards $pageCards,
        #[AutowireLocator('app.social_composer_defaults', defaultIndexMethod: 'network')]
        private ServiceLocator $defaults,
    ) {
    }

    public function ofReply(SocialPostModel $post, ResponseTargetModel $target): ComposerContextModel
    {
        $defaults = $this->defaults->get($post->account->getNetwork()->value)
            ->defaultsFor($post, $this->threadStarterOf($target, $post));
        $text = ComposerStartingText::mentioning($defaults->mentions);

        return $this->contextOf($post, ResponseKind::Reply, $defaults, $text);
    }

    public function ofQuote(SocialPostModel $post): ComposerContextModel
    {
        return $this->quoteContextOf($post, '');
    }

    public function ofLink(SocialAccount $account, SharedLinkModel $link): ComposerContextModel
    {
        $defaults = $this->defaults->get($account->getNetwork()->value)->forAccount($account);

        return new ComposerContextModel(
            $this->connectedBeside($account),
            $account,
            canSend: true,
            defaults: $defaults,
            target: $this->pageCards->cardOf($link->url),
            kind: ComposerKind::Link,
            text: ComposerStartingText::sharing($account->getNetwork(), $link, $defaults->maxCharacters),
        );
    }

    /** A shared link that names a quotable post quotes it, starting with the selection the reader shares. */
    public function ofQuotedLink(SocialPostModel $post, SharedLinkModel $link): ComposerContextModel
    {
        return $this->quoteContextOf($post, $link->quote);
    }

    /** A quote names nobody, so it never asks for the thread's starter. */
    private function quoteContextOf(SocialPostModel $post, string $quote): ComposerContextModel
    {
        $defaults = $this->defaults->get($post->account->getNetwork()->value)
            ->defaultsFor($post, static fn (): ?SocialPostModel => null)
            ->withoutMentions();
        $text = ComposerStartingText::quoting($quote, $defaults->maxCharacters);

        return $this->contextOf($post, ResponseKind::Quote, $defaults, $text);
    }

    private function contextOf(
        SocialPostModel $post,
        ResponseKind $kind,
        ComposerDefaultsModel $defaults,
        string $text,
    ): ComposerContextModel {
        return new ComposerContextModel(
            $this->connectedBeside($post->account),
            $post->account,
            $post->responseContext->allows($kind),
            $defaults,
            $post->responseContext->preview,
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
    private function threadStarterOf(ResponseTargetModel $target, SocialPostModel $post): \Closure
    {
        if (null === $target->address) {
            return static fn (): SocialPostModel => $post;
        }

        return fn (): ?SocialPostModel => $this->threadStarterOrNull($post, $target);
    }

    private function threadStarterOrNull(SocialPostModel $post, ResponseTargetModel $target): ?SocialPostModel
    {
        try {
            return $this->posts->findAs($post->account, SocialPostAddress::ofOrRefuse($target->entry));
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
