<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialAccountNotFoundException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\ComposerContextModel;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\LinkPostModel;
use App\Service\Social\Model\LinkTargetModel;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Url\UrlNormalizer;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class LinkPosts
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private SocialAccountForUser $accountsForUser,
        private UrlNormalizer $urls,
        private PostWriting $writing,
        private SharedPostQuotes $quotes,
        #[AutowireLocator('app.social_composer_defaults', defaultIndexMethod: 'network')]
        private ContainerInterface $defaults,
    ) {
    }

    /**
     * @throws SocialPostRefusedException when the URL is no link to share
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    public function contextFor(User $user, LinkTargetModel $target): ComposerContextModel
    {
        $url = $this->sharable($target->url);
        $connected = $this->socialAccounts->findConnectedOnNetwork($user, $target->network);
        $account = null === $target->accountId
            ? ($connected[0] ?? throw self::noAccountOn($target->network))
            : $this->accountsForUser->requireConnectedOn($user, $target->accountId, $target->network);

        return new ComposerContextModel(
            $connected,
            $account,
            canSend: true,
            defaults: $this->defaultsOf($account),
            quote: $this->quotes->quotableAt($account, $url)?->responseContext->preview,
        );
    }

    /**
     * Quotes the post the URL names on the network it goes to, else shares the URL as a link.
     *
     * @throws SocialPostRefusedException when the URL is no link to share
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostTooLongException
     * @throws SocialTokenScopeMissingException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function share(User $user, LinkPostModel $post): PublishedPostModel
    {
        $url = $this->sharable($post->target->url);
        $account = $this->actingAccount($user, $post->target);
        $quoted = $this->quotes->quotableAt($account, $url);

        return null === $quoted
            ? $this->writing->share($account, $url, $post->draft)
            : $this->writing->quote($quoted, $post->draft);
    }

    /**
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     */
    private function actingAccount(User $user, LinkTargetModel $target): SocialAccount
    {
        if (null !== $target->accountId) {
            return $this->accountsForUser->requireConnectedOn($user, $target->accountId, $target->network);
        }

        return $this->socialAccounts->findReadyActingOnNetwork($user, $target->network)
            ?? throw self::noAccountOn($target->network);
    }

    /** @throws SocialPostRefusedException */
    private function sharable(string $url): string
    {
        return $this->urls->normalize($url) ?? throw new SocialPostRefusedException('This is no link to share.');
    }

    private static function noAccountOn(SocialNetwork $network): NoSocialAccountForNetworkException
    {
        return new NoSocialAccountForNetworkException(
            sprintf('No %s account is connected to post this link.', $network->displayName()),
        );
    }

    private function defaultsOf(SocialAccount $account): ComposerDefaultsModel
    {
        $defaults = $this->defaults->get($account->getNetwork()->value);
        \assert($defaults instanceof ComposerDefaultsInterface);

        return $defaults->forAccount($account);
    }
}
