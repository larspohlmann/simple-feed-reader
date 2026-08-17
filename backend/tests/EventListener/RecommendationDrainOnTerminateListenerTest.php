<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Kernel;
use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Tests\Support\RecordingProcessLauncher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The spawn is only safe to move behind terminate if the listener genuinely
 * fires on both exits and genuinely waits for them. So, like
 * DeferredMailFlushListenerTest, this drives the real kernel rather than
 * calling the listener's methods directly: handle() alone must never launch,
 * and only terminate() (kernel.terminate for HTTP, console.terminate for the
 * CLI) may.
 *
 * The real container's DetachedProcessLauncherInterface is swapped for a
 * RecordingProcessLauncher (config/services_test.yaml) so the launch this
 * proves is the one the container-built listener and spawner actually make,
 * not a hand-assembled stand-in.
 */
final class RecommendationDrainOnTerminateListenerTest extends KernelTestCase
{
    private Kernel $bootedKernel;
    private EntityManagerInterface $entityManager;
    private RecordingProcessLauncher $launcher;
    private User $user;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->bootedKernel = $kernel;

        $this->launcher = new RecordingProcessLauncher();
        self::getContainer()->set(DetachedProcessLauncherInterface::class, $this->launcher);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('drain-terminate@example.test');
    }

    public function testHandleAloneSpawnsNothingAndTerminateSpawnsExactlyOnce(): void
    {
        $this->persistActiveRun();

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);

        self::assertSame([], $this->launcher->launches, 'handle() must not spawn before terminate runs');

        $this->bootedKernel->terminate($request, $response);

        self::assertSame([[RecommendationDrainSpawner::DRAIN_COMMAND, '--detach']], $this->launcher->launches);
    }

    /**
     * No heartbeat is marked in this case, so if the listener reached
     * spawnIfNoWorker() anyway it would find nobody driving the runs and
     * launch — exactly the same shape as the fresh-heartbeat case below,
     * minus the heartbeat. An empty launch list here therefore proves the
     * hasActiveRun() guard, not a presence read that happened to say no.
     */
    public function testNoActiveRunSpawnsNothingAndNeverReachesThePresenceRead(): void
    {
        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([], $this->launcher->launches);
    }

    public function testAFreshWorkerHeartbeatSuppressesTheSpawn(): void
    {
        $this->persistActiveRun();
        $this->presence()->mark(RecommendationDriverKind::PersistentWorker);

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([], $this->launcher->launches);
    }

    /**
     * The drainer surrenders its own liveness key before it exits (see
     * WorkerPresence::forget()), so at the moment its own console.terminate
     * fires it looks exactly like "nobody is driving the runs" to the
     * presence read. Without this exclusion every drain run would fork its
     * own successor on its way out.
     */
    public function testConsoleTerminateForTheDrainCommandItselfSpawnsNothing(): void
    {
        $this->persistActiveRun();

        $this->dispatchConsoleTerminate(RecommendationDrainSpawner::DRAIN_COMMAND);

        self::assertSame([], $this->launcher->launches);
    }

    public function testConsoleTerminateForAnyOtherCommandSpawns(): void
    {
        $this->persistActiveRun();

        $this->dispatchConsoleTerminate('app:feeds:refresh');

        self::assertSame([[RecommendationDrainSpawner::DRAIN_COMMAND, '--detach']], $this->launcher->launches);
    }

    /**
     * ConsoleEvent::getCommand() is typed ?Command because some console
     * events genuinely carry no command (a lookup that failed before one
     * resolved); ConsoleTerminateEvent's own constructor never actually
     * accepts null, so nothing in this app can produce that case through the
     * public API. The null-safe call guards the wider contract anyway --
     * forcing the property directly is the only way to prove that guard
     * still does its job instead of a hard TypeError.
     */
    public function testConsoleTerminateWithNoResolvedCommandStillSpawns(): void
    {
        $this->persistActiveRun();

        $event = new ConsoleTerminateEvent(new Command('placeholder'), new ArrayInput([]), new NullOutput(), 0);
        $commandProperty = new \ReflectionProperty($event, 'command');
        $commandProperty->setValue($event, null);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch($event, ConsoleEvents::TERMINATE);

        self::assertSame([[RecommendationDrainSpawner::DRAIN_COMMAND, '--detach']], $this->launcher->launches);
    }

    /**
     * Reproduces MaintenanceTick's aborted-refresh scenario from the other
     * side: a closed EntityManager must not turn a listener that runs after
     * every response into a fatal. Persisting the run happens first, while
     * the manager is still open; the close happens only afterwards, so the
     * run genuinely is active and only the guard is what stops the read.
     */
    public function testAClosedEntityManagerIsSurvivedWithoutLaunchingOrThrowing(): void
    {
        $this->persistActiveRun();
        $this->entityManager->close();

        $request = $this->healthRequest();
        $response = $this->bootedKernel->handle($request);
        $this->bootedKernel->terminate($request, $response);

        self::assertSame([], $this->launcher->launches);
    }

    private function persistActiveRun(): void
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    private function healthRequest(): Request
    {
        return Request::create('/api/health');
    }

    private function dispatchConsoleTerminate(string $commandName): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $event = new ConsoleTerminateEvent(new Command($commandName), new ArrayInput([]), new NullOutput(), 0);
        $dispatcher->dispatch($event, ConsoleEvents::TERMINATE);
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
