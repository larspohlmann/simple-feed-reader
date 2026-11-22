<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use Doctrine\ORM\EntityManagerInterface;

/** Liked and upvoted posts of the reading-history tests (#2468), on a real entity manager; the caller flushes. */
final class LikedPostFixtures
{
    public static function linkNewItem(
        EntityManagerInterface $entityManager,
        Entry $entry,
        CanonicalItemKind $kind,
        string $canonicalKey,
    ): CanonicalItem {
        $item = CanonicalItems::buildItem($kind, $canonicalKey);
        $entityManager->persist($item);
        $entry->linkTo($item);

        return $item;
    }

    public static function persistLike(
        EntityManagerInterface $entityManager,
        User $user,
        CanonicalItem $item,
        \DateTimeImmutable $likedAt,
    ): SocialLike {
        $account = self::ensureAccount($entityManager, $user, SocialNetwork::Mastodon);
        $like = new SocialLike($user, $item, $account, 'like-' . $item->getCanonicalKey(), $likedAt);
        $entityManager->persist($like);

        return $like;
    }

    public static function persistVote(
        EntityManagerInterface $entityManager,
        User $user,
        CanonicalItem $item,
        VoteScore $score,
        \DateTimeImmutable $votedAt,
    ): DiscussionVote {
        $account = self::ensureAccount($entityManager, $user, SocialNetwork::Lemmy);
        $vote = new DiscussionVote($user, $item, $account, $score, $votedAt);
        $entityManager->persist($vote);

        return $vote;
    }

    private static function ensureAccount(
        EntityManagerInterface $entityManager,
        User $user,
        SocialNetwork $network,
    ): SocialAccount {
        $account = $entityManager->getRepository(SocialAccount::class)
            ->findOneBy(['user' => $user, 'network' => $network]);
        if (null !== $account) {
            return $account;
        }

        return SocialFeeds::persistedAccount(
            $entityManager,
            SocialNetwork::Lemmy === $network ? SocialAccounts::lemmyOf($user) : SocialAccounts::mastodonOf($user),
        );
    }

    private function __construct()
    {
    }
}
