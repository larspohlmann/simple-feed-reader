<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Run\RecommendationDrainSpawner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Spawns the recommendation drainer after the response is sent, at most once per request. HTTP only: on
 * console.terminate every command, the drain command included, would fork a drainer.
 */
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
final readonly class RecommendationDrainOnTerminateListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunRepository $runs,
        private RecommendationDrainSpawner $spawner,
        private LoggerInterface $logger,
    ) {
    }

    public function onKernelTerminate(): void
    {
        // A tick that aborted its refresh leaves the manager closed, and even
        // the existence read is off-limits then.
        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            if (!$this->runs->hasActiveRun()) {
                return;
            }
            $this->spawner->spawnIfNoWorker();
        } catch (\Throwable $failure) {
            // A response is already on the wire. Failing to spawn costs the
            // next cron tick; raising here would cost the request.
            $this->logger->warning('Deferred drainer spawn failed', ['exception' => $failure]);
        }
    }
}
