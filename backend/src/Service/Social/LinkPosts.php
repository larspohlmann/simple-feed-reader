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
        #[AutowireLocator('app.social_composer_defaults', defaultIndexMethod: 'network')]
        private ContainerInterface $defaults,
    ) {
    }

    /**
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     */
    public function contextFor(User $user, SocialNetwork $network, ?int $accountId): ComposerContextModel
    {
        $connected = $this->socialAccounts->findConnectedOnNetwork($user, $network);
        $account = null === $accountId
            ? ($connected[0] ?? throw self::noAccountOn($network))
            : $this->accountsForUser->requireConnectedOn($user, $accountId, $network);

        return new ComposerContextModel($connected, $account, canSend: true, defaults: $this->defaultsOf($account));
    }

    /**
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
        $url = $this->urls->normalize($post->url)
            ?? throw new SocialPostRefusedException('This is no link to share.');
        $account = $this->actingAccount($user, $post->network, $post->accountId);

        return $this->writing->share($account, $url, $post->draft);
    }

    /**
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     */
    private function actingAccount(User $user, SocialNetwork $network, ?int $accountId): SocialAccount
    {
        if (null !== $accountId) {
            return $this->accountsForUser->requireConnectedOn($user, $accountId, $network);
        }

        return $this->socialAccounts->findReadyActingOnNetwork($user, $network) ?? throw self::noAccountOn($network);
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
