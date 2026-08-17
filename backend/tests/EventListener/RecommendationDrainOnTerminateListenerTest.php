<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Kernel;
use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Mail\DeferredMailer;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Tests\Support\RecordingProcessLauncher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The spawn is only safe to move behind terminate if the listener genuinely
 * fires on that exit and genuinely waits for it. So, like
 * DeferredMailFlushListenerTest, this drives the real kernel rather than
 * calling the listener's methods directly: handle() alone must never launch,
 * and only terminate() may. A console exit never may at all.
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
     * No console command forks a drainer, whatever it is (#393 review).
     * `app:e2e:purge-users` is the case that proved the point: docs/local-
     * docker.md has you stop the worker before the e2e suites, so its
     * heartbeat ages out, and the purge command that runs at the head of
     * `composer e2e` then forked a drainer that drove runs against the dev
     * database for the length of the suite. The drain command is here too
     * because it surrenders its liveness key before terminating, so it looks
     * like "nobody is driving" to the presence read and would fork its own
     * successor on its way out.
     *
     * A run is active and no heartbeat is marked, so anything that reached
     * spawnIfNoWorker() at all would launch -- the empty list is the listener
     * not being on this event, not a presence read that happened to say no.
     *
     * @param non-empty-string $commandName
     */
    #[DataProvider('consoleCommands')]
    public function testNoConsoleCommandSpawnsADrainer(string $commandName): void
    {
        $this->persistActiveRun();

        $this->dispatchConsoleTerminate($commandName);

        self::assertSame([], $this->launcher->launches);
    }

    /**
     * The control for the three cases above. Each of them proves a listener is
     * absent from ConsoleEvents::TERMINATE by dispatching it and finding
     * nothing launched -- which a dispatch that reached no listener at all
     * would satisfy just as well, and would go on satisfying if the helper or
     * the event name ever went wrong. The HTTP case is no control for it: it
     * proves the fixture over a different channel.
     *
     * DeferredMailFlushListener is the proof, and a container-registered one
     * rather than a listener this test adds: it carries
     * #[AsEventListener(ConsoleTerminateEvent::class)] for exactly the reason
     * the drain spawner no longer does -- console exits send no response, so
     * without it a command's mail would sit in a queue nothing drains. Its
     * flush is observable, so the same dispatch the absence cases use is shown
     * to arrive.
     */
    public function testTheConsoleTerminateDispatchReachesTheListenersThatAreOnIt(): void
    {
        $mailer = $this->deferredMailer();
        $mailer->send(new RawMessage('positive control'), new Envelope(
            new Address('control@example.test'),
            [new Address('drain-terminate@example.test')],
        ));
        self::assertTrue($mailer->hasQueuedMail());

        $this->dispatchConsoleTerminate('app:feeds:refresh');

        self::assertFalse(
            $mailer->hasQueuedMail(),
            'console.terminate must reach the listeners registered on it, or the absence cases prove nothing',
        );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function consoleCommands(): iterable
    {
        yield 'the drainer itself' => [RecommendationDrainSpawner::DRAIN_COMMAND];
        yield 'the e2e purge that runs beside a stopped worker' => ['app:e2e:purge-users'];
        yield 'an unrelated command' => ['app:feeds:refresh'];
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

    private function deferredMailer(): DeferredMailer
    {
        /** @var DeferredMailer $mailer */
        $mailer = self::getContainer()->get(DeferredMailer::class);

        return $mailer;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
