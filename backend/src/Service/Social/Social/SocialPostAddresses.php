<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\PostAddressRule\PostAddressRuleInterface;
use App\Service\Social\Social\Support\SocialIdentity;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Which network post an entry is: the one rule every interaction with a post (a like, a reply) starts from. */
final readonly class SocialPostAddresses
{
    public function __construct(
        #[AutowireLocator('app.social_post_address_rule')]
        private ContainerInterface $rulesByNetwork,
    ) {
    }

    public function selectRule(SocialNetwork $network): PostAddressRuleInterface
    {
        $rule = $this->rulesByNetwork->get($network->value);
        \assert($rule instanceof PostAddressRuleInterface);

        return $rule;
    }

    public function findPost(Entry $entry): ?SocialPostAddressModel
    {
        $network = $entry->getFeed()->getNetwork();

        return null === $network ? null : $this->selectRule($network)->findPost($entry);
    }

    /** @throws NotASocialPostException */
    public function requirePost(Entry $entry): SocialPostAddressModel
    {
        return $this->findPost($entry)
            ?? throw new NotASocialPostException('This entry is not a post this reader can act on.');
    }

    /** @throws NotASocialPostException */
    public function requireItem(Entry $entry): CanonicalItem
    {
        return $entry->getCanonicalItem()
            ?? throw new NotASocialPostException('This entry is not a post this reader can act on.');
    }

    /**
     * A reply in the entry's thread, named by the address its comment carries.
     *
     * @throws NotASocialPostException
     */
    public function requireReply(Entry $entry, string $address): SocialPostAddressModel
    {
        return $this->requireReplyUnder($this->requirePost($entry), $address);
    }

    /**
     * The post an entry can be answered at: any `findPost()` post, else the post its discussion is.
     *
     * @throws NotASocialPostException
     */
    public function requireRespondablePost(Entry $entry): SocialPostAddressModel
    {
        return $this->findPost($entry)
            ?? $this->findDiscussionPost($entry)
            ?? throw new NotASocialPostException('This entry is not a Bluesky, Mastodon or Lemmy post.');
    }

    /**
     * A reply under the entry's post, named by the address its comment carries.
     *
     * @throws NotASocialPostException
     */
    public function requireRespondableReply(Entry $entry, string $address): SocialPostAddressModel
    {
        return $this->requireReplyUnder($this->requireRespondablePost($entry), $address);
    }

    /** The post a shared URL names, whatever its fragment. */
    public function parsePostUrl(string $url): ?SocialPostAddressModel
    {
        $address = explode('#', $url)[0];
        foreach (SocialNetwork::cases() as $network) {
            $post = $this->selectRule($network)->parsePostUrl($address);
            if (null !== $post) {
                return $post;
            }
        }

        return null;
    }

    /** @param list<SocialAccount> $accounts */
    public function findAuthorAmong(Entry $entry, array $accounts): ?SocialAccount
    {
        $post = $this->findPost($entry);
        $identity = null === $post ? null : $this->selectRule($post->network)->findAuthorIdentity($entry, $post);

        return SocialIdentity::findAccount($identity, $accounts);
    }

    private function findDiscussionPost(Entry $entry): ?SocialPostAddressModel
    {
        foreach (SocialNetwork::cases() as $network) {
            $post = $this->selectRule($network)->findDiscussionPost($entry);
            if (null !== $post) {
                return $post;
            }
        }

        return null;
    }

    /** @throws NotASocialPostException */
    private function requireReplyUnder(SocialPostAddressModel $post, string $address): SocialPostAddressModel
    {
        return $this->selectRule($post->network)->parseReply($address)
            ?? throw new NotASocialPostException('The reply is no post on the entry\'s network.');
    }
}
