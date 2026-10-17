<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ThreadPost;
use App\Repository\ThreadPostRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Factory\ThreadPostFactory;
use App\Service\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use App\Service\Social\SelfReplyChain;
use App\Service\Social\SelfThreadEnricher;
use App\Service\Social\SelfThreadFolder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SelfThreadEnrichers
{
    private function __construct()
    {
    }

    public static function build(
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        ReplyThreadSourceInterface $source,
        LoggerInterface $logger,
    ): SelfThreadEnricher {
        return new SelfThreadEnricher(
            $entityManager,
            PostEnrichers::enrichments($entityManager),
            new ServiceLocator(['bluesky' => static fn () => $source, 'mastodon' => static fn () => $source]),
            new SelfReplyChain(),
            new SelfThreadFolder($entityManager, new ThreadPostFactory(), PostEnrichers::linkCards($entityManager)),
            self::threadPosts($entityManager),
            new NaiveUtcClock($clock),
            $logger,
        );
    }

    /** An enricher over a source that answers nothing: for refresh tests with no self-thread rows. */
    public static function idle(EntityManagerInterface $entityManager, ClockInterface $clock): SelfThreadEnricher
    {
        return self::build($entityManager, $clock, new FakeReplyThreadSource(), new NullLogger());
    }

    public static function threadPosts(EntityManagerInterface $entityManager): ThreadPostRepository
    {
        /** @var ThreadPostRepository $threadPosts */
        $threadPosts = $entityManager->getRepository(ThreadPost::class);

        return $threadPosts;
    }
}
