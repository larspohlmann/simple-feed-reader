<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\RecommendationDrainSpawner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Moves the on-demand drainer's spawn (#371) off the response path and onto
 * the exit hook (#393): a fork that happens after the response is already on
 * the wire costs the request nothing, where forking inline used to add a
 * full Symfony boot to every request that started or resumed a run.
 *
 * A listener fires once per request or console command, which is exactly the
 * scope the old inline call sites needed a `$launched` flag to fake: with the
 * spawn moved here, "at most once per process" is structural, and
 * RecommendationDrainSpawner itself remembers nothing (#393).
 */
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate')]
#[AsEventListener(event: ConsoleTerminateEvent::class, method: 'onConsoleTerminate')]
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
        $this->spawnIfRunsNeedDriving();
    }

    /**
     * The drainer surrenders its liveness key before it terminates, so at this
     * point it looks absent to the presence read and would fork its own
     * successor at every exit.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (RecommendationDrainSpawner::DRAIN_COMMAND === $event->getCommand()?->getName()) {
            return;
        }

        $this->spawnIfRunsNeedDriving();
    }

    private function spawnIfRunsNeedDriving(): void
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
