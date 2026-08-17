<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\RecommendationDrainSpawner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Moves the on-demand drainer's spawn (#371) off the response path and onto
 * the exit hook (#393): a fork that happens after the response is already on
 * the wire costs the request nothing, where forking inline used to add a
 * full Symfony boot to every request that started or resumed a run.
 *
 * A listener fires once per request, which is exactly the scope the old inline
 * call sites needed a `$launched` flag to fake: with the spawn moved here, "at
 * most once per process" is structural, and RecommendationDrainSpawner itself
 * remembers nothing (#393).
 *
 * HTTP only, on purpose. Every way a run comes to need driving arrives over
 * HTTP -- a reader starts or resumes one, or the cron sweep does through
 * /maintenance/tick -- and the two console commands that touch runs need no
 * fork of their own: the drain command IS the drainer (and surrenders its
 * liveness key before terminating, so it would fork its own successor at
 * every exit), and the worker's `messenger:consume` is the driver for as long
 * as it lives. Listening on console.terminate as well made every unrelated
 * command a spawn trigger: with the worker stopped -- which docs/local-docker
 * .md tells you to do before the e2e suites -- `app:e2e:purge-users` forked a
 * drainer that then drove runs against the dev database for the whole run.
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
