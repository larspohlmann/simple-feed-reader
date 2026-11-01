<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class ComposerContexts
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialPostFinder $posts,
        #[AutowireLocator('app.social_composer_defaults', defaultIndexMethod: 'network')]
        private ContainerInterface $defaults,
    ) {
    }

    public function ofResponse(
        SocialPostModel $post,
        ResponseTargetModel $target,
        ResponseKind $kind,
    ): ComposerContextModel {
        if (ResponseKind::Quote === $kind) {
            return $this->ofQuote($post);
        }

        $defaults = $this->defaultsOn($post->account)->defaultsFor($post, $this->threadStarterOf($target, $post));

        return $this->contextOf($post, ResponseKind::Reply, $defaults);
    }

    /** A quote names nobody, so it never asks for the thread's starter. */
    public function ofQuote(SocialPostModel $post): ComposerContextModel
    {
        $defaults = $this->defaultsOn($post->account)
            ->defaultsFor($post, static fn (): ?SocialPostModel => null)
            ->withoutMentions();

        return $this->contextOf($post, ResponseKind::Quote, $defaults);
    }

    public function ofLink(SocialAccount $account, LinkCardContent $card): ComposerContextModel
    {
        return new ComposerContextModel(
            $this->connectedBeside($account),
            $account,
            canSend: true,
            defaults: $this->defaultsOn($account)->forAccount($account),
            target: $card,
        );
    }

    private function contextOf(
        SocialPostModel $post,
        ResponseKind $kind,
        ComposerDefaultsModel $defaults,
    ): ComposerContextModel {
        return new ComposerContextModel(
            $this->connectedBeside($post->account),
            $post->account,
            $post->responseContext->allows($kind),
            $defaults,
            $post->responseContext->preview,
        );
    }

    /** @return list<SocialAccount> */
    private function connectedBeside(SocialAccount $account): array
    {
        return $this->socialAccounts->findConnectedOnNetwork($account->getUser(), $account->getNetwork());
    }

    private function defaultsOn(SocialAccount $account): ComposerDefaultsInterface
    {
        $defaults = $this->defaults->get($account->getNetwork()->value);
        \assert($defaults instanceof ComposerDefaultsInterface);

        return $defaults;
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
