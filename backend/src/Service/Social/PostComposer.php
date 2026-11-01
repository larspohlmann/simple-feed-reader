<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\ResponseTargetModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class PostComposer
{
    public function __construct(
        private ResponseTargetFinder $targets,
        private SocialPostFinder $posts,
        private SocialAccountRepository $socialAccounts,
        #[AutowireLocator('app.social_composer_defaults', defaultIndexMethod: 'network')]
        private ContainerInterface $defaults,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function contextFor(User $user, ResponseTargetModel $target, ResponseKind $kind): ComposerContextModel
    {
        $post = $this->targets->find($user, $target);
        $network = $post->account->getNetwork();
        $defaults = $this->defaults->get($network->value);
        \assert($defaults instanceof ComposerDefaultsInterface);

        return new ComposerContextModel(
            $this->socialAccounts->findConnectedOnNetwork($user, $network),
            $post->account,
            match ($kind) {
                ResponseKind::Reply => $post->responseContext->canReply,
                ResponseKind::Quote => $post->responseContext->canQuote,
            },
            $defaults->defaultsFor($post, $kind, $this->threadStarterOf($target, $post)),
        );
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
